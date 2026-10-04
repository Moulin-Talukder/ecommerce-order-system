<?php

namespace App\Services\Orders;

use App\Exceptions\Orders\OrderException;
use App\Models\Product;
use InvalidArgumentException;

/**
 * The single authoritative order-total calculation (bcmath, 2 decimals).
 *
 *   line:  unit_price (regular selling price) x qty
 *          - discount_amount ((selling - discount_price) x qty)
 *          = line_total
 *   order: subtotal (sum of line totals)
 *          - discount_total (order-level discount)
 *          + delivery_charge
 *          = total
 *
 * Prices always come from the Product models passed in (loaded and locked by
 * the caller), never from client input.
 */
final class OrderCalculator
{
    private const SCALE = 2;

    /**
     * @param  list<array{product: Product, quantity: int}>  $items
     *
     * @throws OrderException when the order-level discount exceeds the subtotal
     */
    public function calculate(array $items, string $deliveryCharge, string $orderDiscount = '0'): OrderTotals
    {
        $lines = [];
        $subtotal = '0.00';

        foreach ($items as ['product' => $product, 'quantity' => $quantity]) {
            if ($quantity < 1) {
                throw new InvalidArgumentException('Quantities must be at least 1.');
            }

            $unitPrice = $this->money($product->selling_price);
            $discountPerUnit = bcsub($unitPrice, $this->money($product->effectivePrice()), self::SCALE);
            $gross = bcmul($unitPrice, (string) $quantity, self::SCALE);
            $discount = bcmul($discountPerUnit, (string) $quantity, self::SCALE);
            $lineTotal = bcsub($gross, $discount, self::SCALE);

            $lines[] = new OrderLine(
                productId: $product->id,
                productName: $product->name,
                sku: $product->sku,
                quantity: $quantity,
                unitPrice: $unitPrice,
                unitCost: $product->purchase_price === null ? null : $this->money($product->purchase_price),
                discountAmount: $discount,
                lineTotal: $lineTotal,
            );

            $subtotal = bcadd($subtotal, $lineTotal, self::SCALE);
        }

        $deliveryCharge = $this->money($deliveryCharge);
        $orderDiscount = $this->money($orderDiscount);

        if (bccomp($deliveryCharge, '0', self::SCALE) < 0 || bccomp($orderDiscount, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Delivery charge and discount cannot be negative.');
        }

        if (bccomp($orderDiscount, $subtotal, self::SCALE) > 0) {
            throw OrderException::discountExceedsSubtotal($orderDiscount, $subtotal);
        }

        $total = bcadd(bcsub($subtotal, $orderDiscount, self::SCALE), $deliveryCharge, self::SCALE);

        return new OrderTotals($lines, $subtotal, $orderDiscount, $deliveryCharge, $total);
    }

    private function money(string|int|float|null $amount): string
    {
        return bcadd((string) ($amount ?? '0'), '0', self::SCALE);
    }
}
