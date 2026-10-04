<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Whether the transition is allowed is decided by ChangeOrderStatus.
 */
class ChangeOrderStatusRequest extends FormRequest
{
    /**
     * Separate error bag so this form's errors do not appear on other forms of the order page.
     *
     * @var string
     */
    protected $errorBag = 'status';

    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('order'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => [
                Rule::requiredIf(in_array($this->input('status'), [OrderStatus::Cancelled->value, OrderStatus::Returned->value], true)),
                'nullable', 'string', 'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Give a reason when cancelling or returning an order.',
        ];
    }
}
