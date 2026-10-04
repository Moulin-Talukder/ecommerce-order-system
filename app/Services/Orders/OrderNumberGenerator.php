<?php

namespace App\Services\Orders;

/**
 * Generates customer-facing order numbers such as ORD-261004-7K3QXM.
 *
 * Random (not sequential) so they do not reveal order volume, and built from
 * an alphabet without look-alike characters (no 0/O, 1/I/L) so they are easy
 * to read over the phone. Uniqueness is enforced by the unique index; the
 * caller retries on the (astronomically rare) collision.
 */
class OrderNumberGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const RANDOM_LENGTH = 6;

    public function generate(): string
    {
        $random = '';

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return config('orders.number_prefix').'-'.now()->format('ymd').'-'.$random;
    }
}
