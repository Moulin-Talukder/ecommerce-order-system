<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Services\Orders\OrderLine;

/**
 * One cart row: the current product, the quantity, the most the customer may
 * order, and server-calculated prices (from OrderCalculator).
 */
final readonly class CartLine
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public int $maxQuantity,
        public OrderLine $pricing,
    ) {}
}
