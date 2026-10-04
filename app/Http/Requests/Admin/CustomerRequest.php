<?php

namespace App\Http\Requests\Admin;

use App\Models\Customer;
use App\Rules\BangladeshMobile;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create/update a customer.
 *
 * Phones are normalized with PhoneNumber *before* validation, so the unique
 * check compares canonical numbers: "+880 1712-345678" and "01712345678" are
 * the same customer. Status is changed only through the dedicated action.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->customer();

        return $customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

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
            'name' => ['required', 'string', 'max:150'],
            'phone' => [
                'bail', 'required', 'string', 'max:30', new BangladeshMobile,
                Rule::unique('customers', 'phone')->ignore($this->customer()),
            ],
            'alternate_phone' => ['bail', 'nullable', 'string', 'max:30', new BangladeshMobile, 'different:phone'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'Another customer already uses this phone number.',
            'alternate_phone.different' => 'The alternate phone must be different from the primary phone.',
            'status.prohibited' => 'Customer status cannot be changed here. Use Block / Unblock on the customer page.',
        ];
    }

    private function customer(): ?Customer
    {
        return $this->route('customer');
    }
}
