<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Activate/deactivate a brand or category. Authorization (the model's
 * "update" policy) runs before validation, so users who may not change the
 * record never see validation details. The controller re-checks the same
 * policy.
 */
class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('brand') ?? $this->route('category');

        return $record instanceof Model && $this->user()->can('update', $record);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
