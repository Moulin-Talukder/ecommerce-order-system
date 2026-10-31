<?php

namespace Tests\Feature\QA;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Reports\CustomerReport;
use App\Services\Reports\OrderReport;
use App\Services\Reports\PaymentReport;
use App\Services\Reports\ProductReport;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reconciles every report (and the dashboard) against an independent PHP
 * calculation over the raw rows of a deterministic, mixed dataset: orders
 * of every status, source and payment method spread over 75 days, with
 * discounted lines, payments, refunds and failed attempts. Any double
 * counting, wrong exclusion or boundary error shows up as a mismatch.
 */
class ReportReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const SEED = 20261031;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Dhaka'));
        $this->seedDataset();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        mt_srand();

        parent::tearDown();
    }

    private function seedDataset(): void
    {
        mt_srand(self::SEED);
        $products = Product::factory()->count(6)->create()->values();
        $customers = Customer::factory()->count(12)->create()->values();
        $statuses = OrderStatus::cases();
        $sources = OrderSource::cases();
        $methods = PaymentMethod::cases();

        // The first 12 orders fall in the last 7 days, so every tested period has data.
        for ($i = 0; $i < 60; $i++) {
            $placedAt = CarbonImmutable::parse('2026-10-15 23:59:59', 'Asia/Dhaka')->subDays($i < 12 ? mt_rand(0, 6) : mt_rand(0, 74))->subSeconds(mt_rand(0, 86399));
            $lines = [];
            $subtotal = '0.00';

            foreach (range(1, mt_rand(1, 3)) as $n) {
                $quantity = mt_rand(1, 4);
                $unit = (string) (mt_rand(100, 3000)).'.00';
                $discount = (string) (mt_rand(0, 3) * 25 * $quantity).'.00';
                $line = bcsub(bcmul($unit, (string) $quantity, 2), $discount, 2);
                $lines[] = ['product' => $products[mt_rand(0, 5)], 'quantity' => $quantity, 'unit_price' => $unit, 'discount_amount' => $discount, 'line_total' => $line];
                $subtotal = bcadd($subtotal, $line, 2);
            }

            $orderDiscount = mt_rand(0, 1) ? '0.00' : '50.00';
            $delivery = mt_rand(0, 1) ? '60.00' : '120.00';
            $total = bcadd(bcsub($subtotal, $orderDiscount, 2), $delivery, 2);
            $paid = ['0.00', bcdiv($total, '2', 2), $total, bcadd($total, '10.00', 2)][mt_rand(0, 3)];

            $order = Order::factory()->create([
                'customer_id' => $customers[mt_rand(0, 11)]->id,
                'created_at' => $placedAt,
                'status' => $statuses[mt_rand(0, count($statuses) - 1)],
                'source' => $sources[mt_rand(0, count($sources) - 1)],
                'payment_method' => $methods[mt_rand(0, count($methods) - 1)],
                'subtotal' => $subtotal, 'discount_total' => $orderDiscount, 'delivery_charge' => $delivery, 'total' => $total,
                'amount_paid' => $paid,
                'payment_status' => match (true) {
                    bccomp($paid, '0', 2) === 0 => [PaymentStatus::Pending, PaymentStatus::Failed][mt_rand(0, 1)],
                    bccomp($paid, $total, 2) < 0 => PaymentStatus::PartiallyPaid,
                    default => PaymentStatus::Paid,
                },
            ]);

            foreach ($lines as $line) {
                OrderItem::factory()->for($order)->for($line['product'])->create(array_diff_key($line, ['product' => true]));
            }

            foreach (range(1, mt_rand(0, 3)) as $n) {
                Payment::factory()->for($order)->create([
                    'type' => mt_rand(0, 4) === 0 ? PaymentType::Refund : PaymentType::Payment,
                    'method' => $methods[mt_rand(0, count($methods) - 1)],
                    'status' => [PaymentTransactionStatus::Completed, PaymentTransactionStatus::Completed, PaymentTransactionStatus::Failed, PaymentTransactionStatus::Pending][mt_rand(0, 3)],
                    'amount' => (string) mt_rand(50, 2000).'.00',
                    'paid_at' => $placedAt->addDays(mt_rand(0, 5)),
                    'created_at' => $placedAt->addDays(mt_rand(0, 5)),
                ]);
            }
        }
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function periods(): array
    {
        return [
            'last 7 days' => ['last_7_days', null, null],
            'last 30 days' => ['last_30_days', null, null],
            'this month' => ['this_month', null, null],
            'custom across a month boundary' => ['custom', '2026-08-20', '2026-10-03'],
        ];
    }

    private function money(Collection $rows, string $column): string
    {
        return $rows->reduce(fn (string $sum, $row) => bcadd($sum, (string) $row->{$column}, 2), '0.00');
    }

    #[DataProvider('periods')]
    public function test_every_report_matches_an_independent_calculation(string $range, ?string $from, ?string $to): void
    {
        $period = DashboardPeriod::resolve($range, $from, $to);
        $filters = new ReportFilters($period);
        $nonRevenue = OrderStatus::nonRevenue();

        $inPeriod = Order::with('items')->get()->filter(fn (Order $order) => $order->created_at->between($period->start, $period->end));
        $revenue = $inPeriod->reject(fn (Order $order) => in_array($order->status, $nonRevenue, true));
        $excluded = $inPeriod->diff($revenue);
        $this->assertGreaterThan(0, $revenue->count(), 'The dataset covers the period.');

        // Sales
        $netSales = $this->money($revenue, 'total');
        $sales = app(SalesReport::class)->summary($filters);
        $this->assertSame([
            $inPeriod->count(), $this->money($inPeriod, 'total'), $excluded->count(), $this->money($excluded, 'total'),
            $revenue->count(), $netSales, $this->money($revenue, 'subtotal'), $this->money($revenue, 'discount_total'),
            $this->money($revenue, 'delivery_charge'), $this->money($revenue->flatMap->items, 'discount_amount'),
            bcdiv($netSales, (string) $revenue->count(), 2),
        ], [
            $sales['orders_placed'], $sales['gross_value'], $sales['excluded_orders'], $sales['excluded_value'],
            $sales['revenue_orders'], $sales['net_sales'], $sales['product_sales'], $sales['order_discounts'],
            $sales['delivery_charges'], $sales['product_discounts'], $sales['average_order_value'],
        ]);
        $this->assertSame($netSales, array_reduce(app(SalesReport::class)->daily($filters), fn (string $sum, array $day) => bcadd($sum, $day['net_sales'], 2), '0.00'), 'Daily rows add up to net sales.');

        // Dashboard agrees with the reports.
        $dashboard = app(DashboardMetrics::class);
        $this->assertSame([$netSales, $revenue->count()], [$dashboard->salesSummary($period)['sales'], $dashboard->salesSummary($period)['revenue_orders']]);
        $this->assertSame($inPeriod->count(), $dashboard->orderSummary($period)['orders']);

        // Orders by status
        foreach (app(OrderReport::class)->byStatus($filters)['rows'] as $row) {
            $matching = $inPeriod->filter(fn (Order $order) => $order->status === $row['status']);
            $this->assertSame([$matching->count(), $this->money($matching, 'total')], [$row['orders'], $row['value']], $row['status']->value);
        }

        // Products
        $lines = $revenue->flatMap->items;
        $this->assertSame(
            ['products' => $lines->pluck('product_id')->unique()->count(), 'units' => (int) $lines->sum('quantity'), 'sales_value' => $this->money($lines, 'line_total')],
            app(ProductReport::class)->summary($filters),
        );

        // Payments: collections by paid_at, completed only, each row once.
        $completed = Payment::query()->where('status', PaymentTransactionStatus::Completed)->get()
            ->filter(fn (Payment $payment) => $payment->paid_at && $payment->paid_at->between($period->start, $period->end));
        $received = $this->money($completed->where('type', PaymentType::Payment), 'amount');
        $refunded = $this->money($completed->where('type', PaymentType::Refund), 'amount');
        $collections = app(PaymentReport::class)->collections($period);
        $this->assertSame([$received, $refunded, bcsub($received, $refunded, 2)], [$collections['payments'], $collections['refunds'], $collections['net']]);
        $this->assertSame($collections['net'], $dashboard->salesSummary($period)['collected'], 'Dashboard collected matches.');

        // Payments: balances of orders placed in the period.
        $due = fn (Order $order) => bccomp($order->total, $order->amount_paid, 2) > 0 ? bcsub($order->total, $order->amount_paid, 2) : '0.00';
        $sumDue = fn (Collection $orders) => $orders->reduce(fn (string $sum, Order $order) => bcadd($sum, $due($order), 2), '0.00');
        $balances = app(PaymentReport::class)->balances($filters);
        $unpaid = $revenue->filter(fn (Order $order) => in_array($order->payment_status, [PaymentStatus::Pending, PaymentStatus::Failed], true));
        $partial = $revenue->filter(fn (Order $order) => $order->payment_status === PaymentStatus::PartiallyPaid);
        $this->assertSame(
            [$this->money($revenue, 'total'), $this->money($revenue, 'amount_paid'), $sumDue($revenue), $unpaid->count(), $sumDue($unpaid), $partial->count(), $sumDue($partial)],
            [$balances['billed'], $balances['received'], $balances['outstanding'], $balances['unpaid_orders'], $balances['unpaid_due'], $balances['partial_orders'], $balances['partial_due']],
        );

        // Customers: new = first revenue order inside the period.
        $allRevenue = Order::all()->reject(fn (Order $order) => in_array($order->status, $nonRevenue, true));
        $buyers = $revenue->pluck('customer_id')->unique();
        $new = $buyers->filter(fn (int $id) => $allRevenue->where('customer_id', $id)->min('created_at')->gte($period->start));
        $this->assertSame(
            ['customers' => $buyers->count(), 'new' => $new->count(), 'returning' => $buyers->count() - $new->count(), 'orders' => $revenue->count(), 'spend' => $netSales],
            app(CustomerReport::class)->summary($period),
        );
    }
}
