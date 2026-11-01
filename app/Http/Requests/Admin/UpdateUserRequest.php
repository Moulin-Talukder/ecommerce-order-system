<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Edit name, email and role. Super Admin accounts (only ever your own, see
 * UserPolicy::update) keep their role: the field is prohibited for them.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->target() !== null && $this->user()->can('update', $this->target());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->target())],
            'role' => $this->target()?->isSuperAdmin()
                ? ['prohibited']
                : ['required', Rule::enum(UserRole::class)->except([UserRole::SuperAdmin])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.enum' => 'Choose Admin, Manager or Staff.',
            'role.prohibited' => 'A Super Admin cannot change their own role.',
        ];
    }

    private function target(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
