<?php

namespace Tests\Unit\Orders;

use App\Exceptions\Orders\OrderException;
use App\Models\Product;
use App\Services\Orders\OrderCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class OrderCalculatorTest extends TestCase
{
    private OrderCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new OrderCalculator;
    }

    private function product(int $id, string $selling, ?string $discount = null, ?string $cost = '100.00'): Product
    {
        return (new Product)->forceFill([
            'id' => $id, 'name' => "Product {$id}", 'sku' => "SKU-{$id}",
            'selling_price' => $selling, 'discount_price' => $discount, 'purchase_price' => $cost,
        ]);
    }

    public function test_single_item_without_discounts(): void
    {
        $totals = $this->calculator->calculate([['product' => $this->product(1, '500.00'), 'quantity' => 2]], '60.00');

        $this->assertSame('1000.00', $totals->subtotal);
        $this->assertSame('0.00', $totals->discountTotal);
        $this->assertSame('60.00', $totals->deliveryCharge);
        $this->assertSame('1060.00', $totals->total);

        $line = $totals->lines[0];
        $this->assertSame(['500.00', '0.00', '1000.00', 2, '100.00'], [$line->unitPrice, $line->discountAmount, $line->lineTotal, $line->quantity, $line->unitCost]);
    }

    public function test_product_sale_price_becomes_a_line_discount(): void
    {
        $totals = $this->calculator->calculate([['product' => $this->product(1, '1500.00', '1290.00'), 'quantity' => 3]], '0');

        $line = $totals->lines[0];
        $this->assertSame('1500.00', $line->unitPrice);
        $this->assertSame('630.00', $line->discountAmount);
        $this->assertSame('3870.00', $line->lineTotal);
        $this->assertSame('3870.00', $totals->subtotal);
    }

    public function test_multiple_items_order_discount_and_delivery(): void
    {
        $totals = $this->calculator->calculate([
            ['product' => $this->product(1, '499.99'), 'quantity' => 3],
            ['product' => $this->product(2, '120.50', '99.95'), 'quantity' => 2],
        ], '80.00', '50.25');

        // 1499.97 + (241.00 - 41.10 = 199.90) = 1699.87; - 50.25 + 80.00 = 1729.62
        $this->assertSame('1699.87', $totals->subtotal);
        $this->assertSame('50.25', $totals->discountTotal);
        $this->assertSame('1729.62', $totals->total);
        $this->assertSame('41.10', $totals->lines[1]->discountAmount);
    }

    public function test_amounts_are_exact_decimals_without_float_drift(): void
    {
        // 0.10 x 3 is 0.30000000000000004 in floating point.
        $totals = $this->calculator->calculate([['product' => $this->product(1, '0.10'), 'quantity' => 3]], '0.20');

        $this->assertSame('0.30', $totals->subtotal);
        $this->assertSame('0.50', $totals->total);
    }

    public function test_unknown_cost_is_kept_as_null(): void
    {
        $totals = $this->calculator->calculate([['product' => $this->product(1, '10.00', cost: null), 'quantity' => 1]], '0');

        $this->assertNull($totals->lines[0]->unitCost);
    }

    public function test_discount_may_equal_but_not_exceed_the_subtotal(): void
    {
        $this->assertSame('60.00', $this->calculator->calculate([['product' => $this->product(1, '100.00'), 'quantity' => 1]], '60.00', '100.00')->total);

        $this->expectException(OrderException::class);
        $this->calculator->calculate([['product' => $this->product(1, '100.00'), 'quantity' => 1]], '60.00', '100.01');
    }

    public function test_invalid_quantities_and_negative_charges_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate([['product' => $this->product(1, '100.00'), 'quantity' => 0]], '0');
    }

    public function test_negative_delivery_charge_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate([['product' => $this->product(1, '100.00'), 'quantity' => 1]], '-1');
    }
}
