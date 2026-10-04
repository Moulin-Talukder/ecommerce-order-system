<?php

namespace App\Casts;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores phone attributes in canonical form via {@see PhoneNumber}.
 *
 * Invalid numbers throw instead of being stored, so a non-normalized phone can
 * never reach the database. Validate input (Form Requests) before assigning.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class PhoneNumberCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return PhoneNumber::normalize((string) $value)
            ?? throw new InvalidArgumentException("Invalid phone number for [{$key}].");
    }
}
