<?php

namespace App\Exceptions\Inventory;

use App\Models\Product;

class InsufficientStockException extends StockException
{
    public static function for(Product $product, int $requested): self
    {
        return new self("Not enough stock for “{$product->name}” ({$product->sku}): {$product->stock_quantity} available, {$requested} requested.");
    }
}
