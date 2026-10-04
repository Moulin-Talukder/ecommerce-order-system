<?php

namespace App\Http\Requests\Admin;

use App\Enums\StockMovementType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual stock change by an admin. Sale/cancellation/return movements are
 * reserved for the order workflow and cannot be entered here.
 */
class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('adjustStock', $this->route('product'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(StockMovementType::class)->only([StockMovementType::Restock, StockMovementType::Adjustment])],
            'quantity_change' => [
                'required', 'integer', 'not_in:0', 'between:-1000000,1000000',
                Rule::when($this->input('type') === StockMovementType::Restock->value, ['min:1']),
            ],
            'note' => [
                Rule::requiredIf($this->input('type') === StockMovementType::Adjustment->value),
                'nullable', 'string', 'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity_change.not_in' => 'The quantity change cannot be zero.',
            'quantity_change.min' => 'A restock must add at least one unit. Use an adjustment to remove stock.',
            'note.required' => 'Explain the reason for a manual adjustment (e.g. damaged, stock count correction).',
        ];
    }
}
