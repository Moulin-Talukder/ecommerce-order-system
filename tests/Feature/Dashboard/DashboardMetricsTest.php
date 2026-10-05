<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\CourierShipment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Orders\OrderSearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private DashboardMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 14:00:00', 'Asia/Dhaka'));
        $this->metrics = app(DashboardMetrics::class);
    }

    private function order(string $createdAt, OrderStatus $status, string $total, array $attributes = []): Order
    {
        $order = Order::factory()->status($status)->create(['total' => $total] + $attributes);
        $order->forceFill(['created_at' => CarbonImmutable::parse($createdAt, 'Asia/Dhaka')] + $attributes)->save();

        return $order;
    }

    private function period(string $range): DashboardPeriod
    {
        return DashboardPeriod::resolve($range, now: CarbonImmutable::now());
    }

    // --- Orders & sales -------------------------------------------------------------------

    public function test_today_counts_and_sales_exclude_cancelled_and_returned_orders(): void
    {
        $this->order('2026-10-15 00:00:00', OrderStatus::Pending, '1000.00');
        $this->order('2026-10-15 09:30:00', OrderStatus::Delivered, '2500.50');
        $this->order('2026-10-15 23:59:59', OrderStatus::Cancelled, '700.00');
        $this->order('2026-10-15 10:00:00', OrderStatus::Returned, '300.00');
        $this->order('2026-10-14 23:59:59', OrderStatus::Delivered, '9999.00');
        $this->order('2026-10-16 00:00:00', OrderStatus::Pending, '8888.00');

        $orders = $this->metrics->orderSummary($this->period('today'));
        $sales = $this->metrics->salesSummary($this->period('today'));

        $this->assertSame(4, $orders['orders']);
        $this->assertSame(2, $orders['cancelled_or_returned']);
        $this->assertSame(['pending' => 1, 'confirmed' => 0, 'processing' => 0, 'shipped' => 0, 'delivered' => 1, 'cancelled' => 1, 'returned' => 1], $orders['by_status']);
        $this->assertSame('3500.50', $sales['sales']);
        $this->assertSame(2, $sales['revenue_orders']);
        $this->assertSame('1750.25', $sales['average_order_value']);
    }

    public function test_period_boundaries_follow_the_selected_range(): void
    {
        $this->order('2026-10-09 00:00:01', OrderStatus::Confirmed, '100.00');
        $this->order('2026-10-08 23:59:59', OrderStatus::Confirmed, '200.00');
        $this->order('2026-10-01 08:00:00', OrderStatus::Delivered, '400.00');
        $this->order('2026-09-30 22:00:00', OrderStatus::Delivered, '800.00');

        $this->assertSame('100.00', $this->metrics->salesSummary($this->period('last_7_days'))['sales']);
        $this->assertSame('700.00', $this->metrics->salesSummary($this->period('this_month'))['sales']);
        $this->assertSame('1500.00', $this->metrics->salesSummary($this->period('last_30_days'))['sales']);
        $this->assertSame(1, $this->metrics->orderSummary(DashboardPeriod::resolve('custom', '2026-09-30', '2026-09-30'))['orders']);
    }

    public function test_sales_are_exact_decimals(): void
    {
        foreach (['0.10', '0.20', '0.30'] as $total) {
            $this->order('2026-10-15 10:00:00', OrderStatus::Pending, $total);
        }

        $sales = $this->metrics->salesSummary($this->period('today'));

        $this->assertSame('0.60', $sales['sales']);
        $this->assertSame('0.20', $sales['average_order_value']);
    }

    public function test_an_empty_period_is_all_zeros(): void
    {
        $orders = $this->metrics->orderSummary($this->period('yesterday'));
        $sales = $this->metrics->salesSummary($this->period('yesterday'));

        $this->assertSame(0, $orders['orders']);
        $this->assertSame(['0.00', 0, '0.00', '0.00'], [$sales['sales'], $sales['revenue_orders'], $sales['average_order_value'], $sales['collected']]);
    }

    public function test_collected_is_net_of_refunds_and_uses_the_payment_date(): void
    {
        $oldOrder = $this->order('2026-09-01 10:00:00', OrderStatus::Delivered, '5000.00');
        Payment::factory()->for($oldOrder)->completed()->create(['amount' => '5000.00', 'paid_at' => '2026-10-15 11:00:00']);
        Payment::factory()->for($oldOrder)->refund()->completed()->create(['amount' => '1200.00', 'paid_at' => '2026-10-15 12:00:00']);
        Payment::factory()->for($oldOrder)->create(['status' => 'failed', 'amount' => '999.00', 'paid_at' => null]);
        Payment::factory()->for($oldOrder)->completed()->create(['amount' => '50.00', 'paid_at' => '2026-10-14 12:00:00']);

        $this->assertSame('3800.00', $this->metrics->salesSummary($this->period('today'))['collected']);
        $this->assertSame('3850.00', $this->metrics->salesSummary($this->period('last_7_days'))['collected']);
        $this->assertSame('0.00', $this->metrics->salesSummary($this->period('today'))['sales'], 'The order was placed in September.');
    }

    public function test_the_daily_series_has_one_entry_per_day_including_empty_days(): void
    {
        $this->order('2026-10-09 10:00:00', OrderStatus::Delivered, '100.00');
        $this->order('2026-10-09 18:00:00', OrderStatus::Cancelled, '50.00');
        $this->order('2026-10-15 09:00:00', OrderStatus::Pending, '250.00');

        $series = $this->metrics->dailySeries($this->period('last_7_days'));

        $this->assertCount(7, $series);
        $this->assertSame(['date' => '2026-10-09', 'label' => '09 Oct', 'orders' => 2, 'sales' => '100.00'], $series[0]);
        $this->assertSame(['date' => '2026-10-10', 'label' => '10 Oct', 'orders' => 0, 'sales' => '0.00'], $series[1]);
        $this->assertSame(['date' => '2026-10-15', 'label' => '15 Oct', 'orders' => 1, 'sales' => '250.00'], $series[6]);
    }

    // --- Outstanding money & queues ------------------------------------------------------------

    public function test_outstanding_amounts_for_delivered_unpaid_and_partially_paid_orders(): void
    {
        $this->order('2026-10-10 10:00:00', OrderStatus::Delivered, '1060.00');
        $partlyDelivered = $this->order('2026-10-10 10:00:00', OrderStatus::Delivered, '2000.00');
        $partlyDelivered->forceFill(['payment_status' => PaymentStatus::PartiallyPaid, 'amount_paid' => '500.00'])->save();
        $partlyOpen = $this->order('2026-10-10 10:00:00', OrderStatus::Confirmed, '800.00');
        $partlyOpen->forceFill(['payment_status' => PaymentStatus::PartiallyPaid, 'amount_paid' => '60.00'])->save();
        $paid = $this->order('2026-10-10 10:00:00', OrderStatus::Delivered, '700.00');
        $paid->forceFill(['payment_status' => PaymentStatus::Paid, 'amount_paid' => '700.00'])->save();
        $cancelledPartly = $this->order('2026-10-10 10:00:00', OrderStatus::Cancelled, '900.00');
        $cancelledPartly->forceFill(['payment_status' => PaymentStatus::PartiallyPaid, 'amount_paid' => '100.00'])->save();

        $outstanding = $this->metrics->outstanding();

        $this->assertSame(2, $outstanding['delivered_unpaid_orders']);
        $this->assertSame('2560.00', $outstanding['delivered_unpaid_amount']);
        $this->assertSame(2, $outstanding['partially_paid_orders']);
        $this->assertSame('2240.00', $outstanding['partially_paid_amount']);
    }

    public function test_queue_counts_match_the_order_list_queues(): void
    {
        Order::factory()->count(2)->create();
        Order::factory()->status(OrderStatus::Processing)->create();
        $booked = Order::factory()->status(OrderStatus::Processing)->create();
        CourierShipment::factory()->for($booked)->create();

        $counts = $this->metrics->queueCounts();

        $this->assertSame(app(OrderSearch::class)->viewCounts(), $counts);
        foreach ($counts as $view => $count) {
            $this->assertSame($count, app(OrderSearch::class)->viewQuery($view)->count(), "Queue {$view}");
        }
        $this->assertSame([2, 2, 1], [$counts['needs_confirmation'], $counts['to_fulfil'], $counts['ready_for_courier']]);
    }

    // --- Recent orders & stock ---------------------------------------------------------------

    public function test_recent_orders_are_newest_first_and_limited(): void
    {
        $orders = collect(range(1, 10))->map(fn ($i) => $this->order(sprintf('2026-10-15 %02d:00:00', $i), OrderStatus::Pending, '10.00'));

        $recent = $this->metrics->recentOrders(8);

        $this->assertCount(8, $recent);
        $this->assertSame($orders->reverse()->take(8)->pluck('order_number')->values()->all(), $recent->pluck('order_number')->all());
    }

    public function test_products_needing_restock_reuse_the_existing_stock_rules(): void
    {
        $low = Product::factory()->withStock(3)->create(['name' => 'Low Item', 'low_stock_threshold' => 5]);
        $atThreshold = Product::factory()->withStock(5)->create(['name' => 'At Threshold', 'low_stock_threshold' => 5]);
        $empty = Product::factory()->outOfStock()->create(['name' => 'Empty Item']);
        $manualOut = Product::factory()->create(['name' => 'Manual Out', 'manage_stock' => false]);
        $manualOut->forceFill(['stock_status' => StockStatus::OutOfStock])->save();
        Product::factory()->withStock(50)->create(['name' => 'Plenty', 'low_stock_threshold' => 5]);
        Product::factory()->withStock(2)->create(['name' => 'No Threshold']);
        Product::factory()->outOfStock()->create(['name' => 'Archived Empty', 'status' => ProductStatus::Archived]);

        $restock = $this->metrics->productsNeedingRestock();

        $this->assertSame(4, $restock['total']);
        $this->assertSame(['Empty Item', 'Manual Out', 'Low Item', 'At Threshold'], $restock['products']->pluck('name')->all());
        $this->assertTrue($restock['products']->every(fn (Product $product) => $product->isLowStock() || $product->stock_status === StockStatus::OutOfStock));
        $this->assertTrue($low->isLowStock() && $atThreshold->isLowStock() && $empty->stock_status === StockStatus::OutOfStock);
    }

    public function test_the_low_stock_scope_matches_is_low_stock(): void
    {
        $products = collect([
            Product::factory()->withStock(3)->create(['low_stock_threshold' => 5]),
            Product::factory()->withStock(6)->create(['low_stock_threshold' => 5]),
            Product::factory()->withStock(0)->create(['low_stock_threshold' => 0]),
            Product::factory()->withStock(1)->create(['low_stock_threshold' => null]),
            Product::factory()->create(['manage_stock' => false, 'low_stock_threshold' => 99]),
        ]);

        $this->assertEqualsCanonicalizing(
            $products->filter(fn (Product $product) => $product->refresh()->isLowStock())->pluck('id')->all(),
            Product::query()->lowStock()->pluck('id')->all(),
        );
    }
}
