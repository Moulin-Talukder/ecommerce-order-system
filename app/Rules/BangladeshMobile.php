<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a Bangladeshi mobile number using the single normalization
 * source of truth, {@see PhoneNumber}.
 */
class BangladeshMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isValid($value)) {
            $fail('The :attribute must be a valid Bangladeshi mobile number, e.g. 01712345678.');
        }
    }
}
