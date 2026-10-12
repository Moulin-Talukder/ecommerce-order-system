<?php

namespace App\Http\Requests\Storefront;

use App\Rules\BangladeshMobile;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Guest checkout details. Only customer and delivery information is
 * accepted: items, prices, delivery charge, payment method and source are
 * decided by the server.
 */
class CheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalize = fn (string $key) => $this->filled($key)
            ? (PhoneNumber::normalize((string) $this->input($key)) ?? $this->input($key))
            : null;

        $this->merge([
            'phone' => $normalize('phone'),
            'alternate_phone' => $normalize('alternate_phone'),
            'email' => $this->filled('email') ? Str::lower(trim((string) $this->input('email'))) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'checkout_token' => ['required', 'string', 'size:40'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['bail', 'required', 'string', 'max:30', new BangladeshMobile],
            'alternate_phone' => ['bail', 'nullable', 'string', 'max:30', new BangladeshMobile, 'different:phone'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'area' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'delivery_zone' => ['required', 'string', Rule::in(array_keys(config('storefront.delivery_zones')))],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alternate_phone.different' => 'The alternate phone must be different from your phone number.',
            'delivery_zone.in' => 'Please choose Inside Dhaka or Outside Dhaka.',
            'delivery_zone.required' => 'Please choose Inside Dhaka or Outside Dhaka.',
            'checkout_token.*' => 'Your checkout session expired. Please review your order and try again.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['area' => 'area / thana', 'city' => 'city / district'];
    }

    /**
     * @return array{name: string, phone: string, alternate_phone: ?string, email: ?string, address: string, area: string, city: string, delivery_zone: string, note: ?string}
     */
    public function details(): array
    {
        return $this->safe()->except('checkout_token') + ['alternate_phone' => null, 'email' => null, 'note' => null];
    }
}
