<?php

namespace App\Enums;

/**
 * Availability shown to customers. Derived from stock_quantity when manage_stock is enabled.
 */
enum StockStatus: string
{
    case InStock = 'in_stock';
    case OutOfStock = 'out_of_stock';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'In stock',
            self::OutOfStock => 'Out of stock',
        };
    }
}
