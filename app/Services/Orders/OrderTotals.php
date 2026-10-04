<?php

namespace App\Services\Orders;

/**
 * Result of {@see OrderCalculator}. All amounts are 2-decimal strings.
 */
final readonly class OrderTotals
{
    /**
     * @param  list<OrderLine>  $lines
     */
    public function __construct(
        public array $lines,
        public string $subtotal,
        public string $discountTotal,
        public string $deliveryCharge,
        public string $total,
    ) {}
}
