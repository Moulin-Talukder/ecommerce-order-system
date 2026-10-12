<?php

namespace App\Support;

/**
 * Normalizes Bangladeshi mobile numbers to the canonical 11-digit local
 * format (e.g. "01712345678").
 *
 * Phone is the customer identity, so every phone number entering the system
 * (checkout, admin customer forms, order snapshots) must go through here.
 */
final class PhoneNumber
{
    private const PATTERN = '/^01[3-9]\d{8}$/';

    /**
     * Convert any accepted input format to the canonical format, or null if
     * the input is not a valid Bangladeshi mobile number.
     *
     * Accepts e.g. "01712345678", "+8801712345678", "8801712345678",
     * "008801712345678", "1712345678", "017-1234 5678".
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return preg_match(self::PATTERN, $digits) ? $digits : null;
    }

    /**
     * Show only the operator prefix and last three digits, e.g. "017•••••678".
     */
    public static function mask(?string $phone): string
    {
        $phone = (string) $phone;

        return strlen($phone) > 6 ? substr($phone, 0, 3).str_repeat('•', strlen($phone) - 6).substr($phone, -3) : '•••';
    }

    public static function isValid(?string $phone): bool
    {
        return self::normalize($phone) !== null;
    }
}
