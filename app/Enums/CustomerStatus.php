<?php

namespace App\Enums;

/**
 * Whether a customer may place orders.
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Blocked => 'Blocked',
        };
    }
}
