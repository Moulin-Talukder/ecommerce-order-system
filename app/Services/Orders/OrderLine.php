<?php

namespace App\Services\Orders;

/**
 * An order line as it will be snapshotted into order_items.
 * All amounts are 2-decimal strings.
 */
final readonly class OrderLine
{
    public function __construct(
        public int $productId,
        public string $productName,
        public string $sku,
        public int $quantity,
        public string $unitPrice,
        public ?string $unitCost,
        public string $discountAmount,
        public string $lineTotal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'unit_cost' => $this->unitCost,
            'discount_amount' => $this->discountAmount,
            'line_total' => $this->lineTotal,
        ];
    }
}
