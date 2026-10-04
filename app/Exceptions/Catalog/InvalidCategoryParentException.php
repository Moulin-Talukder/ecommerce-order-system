<?php

namespace App\Exceptions\Catalog;

use Illuminate\Validation\ValidationException;

/**
 * Thrown when a category would be placed under an invalid parent (itself, one
 * of its descendants, or too deep). Rendered as a validation error on parent_id.
 */
class InvalidCategoryParentException extends ValidationException
{
    public static function because(string $reason): self
    {
        return static::withMessages(['parent_id' => $reason]);
    }
}
