<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a product.
 *
 * Pricing rules live here (plus DB CHECK constraints as the final guard).
 * Stock fields are prohibited: stock only changes through StockService,
 * via opening_stock on create or a stock adjustment afterwards.
 */
class ProductRequest extends FormRequest
{
    private const MAX_MONEY = '9999999999.99';

    public function authorize(): bool
    {
        $product = $this->product();

        return $product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->filled('slug') ? Str::lower(trim((string) $this->input('slug'))) : null,
            'sku' => $this->filled('sku') ? trim((string) $this->input('sku')) : null,
            'manage_stock' => $this->boolean('manage_stock'),
            'is_featured' => $this->boolean('is_featured'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $money = ['decimal:0,2', 'min:0', 'max:'.self::MAX_MONEY];

        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($this->product()),
            ],
            'sku' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/',
                Rule::unique('products', 'sku')->ignore($this->product()),
            ],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],

            'purchase_price' => ['nullable', ...$money],
            'selling_price' => ['required', ...$money],
            'discount_price' => ['nullable', ...$money],

            'manage_stock' => ['required', 'boolean'],
            'opening_stock' => $this->product()
                ? ['prohibited']
                : ['nullable', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'stock_quantity' => ['prohibited'],
            'stock_status' => ['prohibited'],

            'status' => ['required', Rule::enum(ProductStatus::class)],
            'is_featured' => ['required', 'boolean'],

            'weight' => ['nullable', 'decimal:0,3', 'min:0', 'max:99999.999'],
            'length' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'width' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'height' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999.99'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
            'sku.regex' => 'The SKU may only contain letters, numbers, dots, dashes, underscores and slashes, and must start with a letter or number.',
            'stock_quantity.prohibited' => 'Stock quantity cannot be edited here. Use a stock adjustment on the product page.',
            'stock_status.prohibited' => 'Stock status cannot be edited here. It follows the stock quantity.',
            'opening_stock.prohibited' => 'Opening stock can only be set when creating a product. Use a stock adjustment instead.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['sku' => 'SKU'];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateDiscountBelowSellingPrice($validator);
                $this->validateCategoryIsSelectable($validator);
                $this->validateBrandIsSelectable($validator);
            },
        ];
    }

    private function validateDiscountBelowSellingPrice(Validator $validator): void
    {
        $errors = $validator->errors();

        if (! $this->filled('discount_price') || $errors->hasAny(['discount_price', 'selling_price'])) {
            return;
        }

        if (bccomp((string) $this->input('discount_price'), (string) $this->input('selling_price'), 2) >= 0) {
            $errors->add('discount_price', 'The discount price must be lower than the selling price.');
        }
    }

    /**
     * New assignments must use a category that is visible (it and its ancestors active);
     * a product may keep its current category even if that was deactivated.
     */
    private function validateCategoryIsSelectable(Validator $validator): void
    {
        if ($validator->errors()->has('category_id')) {
            return;
        }

        $categoryId = (int) $this->input('category_id');

        if ($categoryId !== $this->product()?->category_id && ! CategoryTree::load()->isEffectivelyActive($categoryId)) {
            $validator->errors()->add('category_id', 'The selected category is inactive (or inside an inactive category).');
        }
    }

    private function validateBrandIsSelectable(Validator $validator): void
    {
        if (! $this->filled('brand_id') || $validator->errors()->has('brand_id')) {
            return;
        }

        $brandId = (int) $this->input('brand_id');

        if ($brandId !== $this->product()?->brand_id && ! Brand::query()->whereKey($brandId)->where('is_active', true)->exists()) {
            $validator->errors()->add('brand_id', 'The selected brand is inactive.');
        }
    }

    private function product(): ?Product
    {
        return $this->route('product');
    }
}
