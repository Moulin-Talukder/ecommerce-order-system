<?php

namespace App\Enums;

/**
 * Direction of a payments ledger row. Amounts are always positive.
 */
enum PaymentType: string
{
    case Payment = 'payment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::Refund => 'Refund',
        };
    }
}
