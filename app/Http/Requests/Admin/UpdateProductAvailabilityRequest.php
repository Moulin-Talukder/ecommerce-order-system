<?php

namespace App\Http\Requests\Admin;

use App\Enums\StockStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual availability for products that do not track stock quantities.
 */
class UpdateProductAvailabilityRequest extends FormRequest
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
            'stock_status' => ['required', Rule::enum(StockStatus::class)],
        ];
    }
}
