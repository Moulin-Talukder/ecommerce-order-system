<?php

namespace App\Exceptions\Orders;

use App\Enums\OrderStatus;

class InvalidOrderTransitionException extends OrderException
{
    public static function between(string $orderNumber, OrderStatus $from, OrderStatus $to): self
    {
        return new self("Order {$orderNumber} cannot move from {$from->label()} to {$to->label()}.");
    }
}
