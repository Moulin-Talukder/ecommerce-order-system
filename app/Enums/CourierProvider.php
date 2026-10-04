<?php

namespace App\Enums;

/**
 * Courier services that shipments can be booked with.
 */
enum CourierProvider: string
{
    case Steadfast = 'steadfast';

    public function label(): string
    {
        return match ($this) {
            self::Steadfast => 'Steadfast Courier',
        };
    }
}
