<?php

namespace App\Enums;

/**
 * Reason for a stock_movements ledger row.
 */
enum StockMovementType: string
{
    case Sale = 'sale';
    case Cancellation = 'cancellation';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case Restock = 'restock';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Cancellation => 'Order cancelled',
            self::Return => 'Order returned',
            self::Adjustment => 'Manual adjustment',
            self::Restock => 'Restock',
        };
    }
}
