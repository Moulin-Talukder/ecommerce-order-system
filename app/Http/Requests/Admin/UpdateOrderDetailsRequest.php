<?php

namespace App\Http\Requests\Admin;

use App\Rules\BangladeshMobile;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Non-financial order corrections. Items, prices, totals, status and payment
 * fields are not accepted here at all.
 */
class UpdateOrderDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    protected function prepareForValidation(): void
    {
        $normalize = fn (string $key) => $this->filled($key)
            ? (PhoneNumber::normalize((string) $this->input($key)) ?? $this->input($key))
            : null;

        if ($this->route('order')->status->allowsDeliveryChanges()) {
            $this->merge([
                'customer_phone' => $normalize('customer_phone'),
                'customer_alternate_phone' => $normalize('customer_alternate_phone'),
                'customer_email' => $this->filled('customer_email') ? Str::lower(trim((string) $this->input('customer_email'))) : null,
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['admin_note' => ['nullable', 'string', 'max:2000']];

        if (! $this->route('order')->status->allowsDeliveryChanges()) {
            return $rules;
        }

        return $rules + [
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['bail', 'required', 'string', 'max:30', new BangladeshMobile],
            'customer_alternate_phone' => ['bail', 'nullable', 'string', 'max:30', new BangladeshMobile, 'different:customer_phone'],
            'customer_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'shipping_address' => ['required', 'string', 'max:500'],
            'shipping_area' => ['nullable', 'string', 'max:100'],
            'shipping_city' => ['nullable', 'string', 'max:100'],
            'shipping_postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
