<?php

namespace App\Http\Requests\Admin;

use App\Enums\CustomerStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Rules\BangladeshMobile;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the shape of an admin-entered order.
 *
 * Only customer/delivery details, product ids, quantities, delivery charge,
 * order discount, payment method, source and notes are accepted. Prices,
 * totals, payment status and stock are never read from the request; the
 * CreateOrder action calculates and re-checks everything under row locks.
 * The early checks in after() exist only to give friendly field errors.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Order::class);
    }

    protected function prepareForValidation(): void
    {
        $normalize = fn (string $key) => $this->filled($key)
            ? (PhoneNumber::normalize((string) $this->input($key)) ?? $this->input($key))
            : null;

        // Drop completely empty item rows left over from the form.
        $items = collect((array) $this->input('items', []))
            ->filter(fn ($item) => is_array($item) && (filled($item['product_id'] ?? null) || filled($item['quantity'] ?? null)))
            ->values()
            ->all();

        $this->merge([
            'customer_phone' => $normalize('customer_phone'),
            'customer_alternate_phone' => $normalize('customer_alternate_phone'),
            'customer_email' => $this->filled('customer_email') ? Str::lower(trim((string) $this->input('customer_email'))) : null,
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['bail', 'required', 'string', 'max:30', new BangladeshMobile],
            'customer_alternate_phone' => ['bail', 'nullable', 'string', 'max:30', new BangladeshMobile, 'different:customer_phone'],
            'customer_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'shipping_address' => ['required', 'string', 'max:500'],
            'shipping_area' => ['nullable', 'string', 'max:100'],
            'shipping_city' => ['nullable', 'string', 'max:100'],
            'shipping_postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/'],

            'items' => ['required', 'array', 'min:1', 'max:'.config('orders.max_items')],
            'items.*.product_id' => ['bail', 'required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('orders.max_quantity_per_item')],

            'delivery_charge' => ['required', 'decimal:0,2', 'min:0', 'max:'.config('orders.max_delivery_charge')],
            'discount_total' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'source' => ['required', Rule::enum(OrderSource::class)],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one product to the order.',
            'items.*.product_id.required' => 'Choose a product for every item row.',
            'items.*.product_id.distinct' => 'Each product may appear only once; adjust the quantity instead.',
            'items.*.product_id.exists' => 'The selected product does not exist.',
            'items.*.quantity.min' => 'Quantities must be at least 1.',
            'customer_alternate_phone.different' => 'The alternate phone must be different from the primary phone.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_phone' => 'phone',
            'customer_name' => 'customer name',
            'items.*.product_id' => 'product',
            'items.*.quantity' => 'quantity',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = $validator->errors();

                if (! $errors->has('customer_phone')) {
                    $customer = Customer::query()->firstWhere('phone', $this->input('customer_phone'));

                    if ($customer?->status === CustomerStatus::Blocked) {
                        $errors->add('customer_phone', "{$customer->name} is blocked and cannot place orders.");
                    }
                }

                $products = Product::query()->whereKey(collect($this->input('items'))->pluck('product_id')->filter()->all())->get()->keyBy('id');

                foreach ((array) $this->input('items') as $index => $item) {
                    $product = $products->get((int) ($item['product_id'] ?? 0));

                    if (! $product || $errors->has("items.{$index}.product_id")) {
                        continue;
                    }

                    if (! $product->isOrderable()) {
                        $errors->add("items.{$index}.product_id", "“{$product->name}” is not available for sale ({$product->status->label()}).");
                    } elseif ($product->manage_stock && ! $errors->has("items.{$index}.quantity") && (int) ($item['quantity'] ?? 0) > $product->stock_quantity) {
                        $errors->add("items.{$index}.quantity", "Only {$product->stock_quantity} of “{$product->name}” in stock.");
                    }
                }
            },
        ];
    }
}
