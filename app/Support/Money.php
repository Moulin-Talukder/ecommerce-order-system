<?php

namespace App\Support;

/**
 * Display formatting for DECIMAL(12,2) amounts. Display only; calculations
 * use bcmath on the decimal strings.
 */
final class Money
{
    private const SYMBOLS = ['BDT' => '৳'];

    public static function format(string|int|float|null $amount, string $currency = 'BDT'): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        return $symbol.number_format((float) $amount, 2);
    }
}
