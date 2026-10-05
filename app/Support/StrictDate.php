<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Parses Y-m-d dates from user input without silent overflow:
 * "2026-02-30" and "2026-13-45" are rejected instead of rolling over.
 */
final class StrictDate
{
    public static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }
}
