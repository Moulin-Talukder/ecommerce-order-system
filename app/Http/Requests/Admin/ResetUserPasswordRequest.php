<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\AdminPassword;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Set a new password for a staff account (Super Admin only).
 */
class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()->can('resetPassword', $target);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', AdminPassword::rule()],
        ];
    }
}
