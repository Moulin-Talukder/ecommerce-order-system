<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 15 reports. "Now" is fixed at 15 Oct 2026 12:00 Asia/Dhaka, so
 * "today" = 15 Oct, "last 7 days" = 9–15 Oct, "this month" = 1–15 Oct.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Dhaka'));
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(string $placedAt, array $attributes = []): Order
    {
        return Order::factory()->create(['created_at' => CarbonImmutable::parse($placedAt, 'Asia/Dhaka')] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function report(string $report, array $parameters = [], ?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test('pages::admin.reports', ['report' => $report] + $parameters);
    }

    // --- Access -----------------------------------------------------------------------------------

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('admin.reports'))->assertRedirect(route('login'));
    }

    public function test_every_admin_role_can_open_reports_and_sees_the_nav_link(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->get(route('admin.reports'))->assertOk()->assertSee('Reports');
            $this->actingAs($user)->get(route('admin.dashboard'))->assertSee(route('admin.reports'), false);
        }
    }

    public function test_users_without_the_report_ability_are_refused_and_do_not_see_the_link(): void
    {
        Gate::before(fn (User $user, string $ability) => $ability === 'viewReports' ? false : null);

        $this->actingAs($this->admin)->get(route('admin.reports'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.reports'), false);
    }

    public function test_inactive_users_are_refused(): void
    {
        $this->actingAs(User::factory()->admin()->inactive()->create())->get(route('admin.reports'))->assertRedirect();
    }

    // --- Sales --------------------------------------------------------------------------------------

    public function test_sales_figures_exclude_cancelled_and_returned_orders_and_count_each_order_once(): void
    {
        $this->order('2026-10-10 10:00', ['subtotal' => '1000.00', 'discount_total' => '0.00', 'delivery_charge' => '60.00', 'total' => '1060.00']);
        $delivered = $this->order('2026-10-12 18:30', ['status' => OrderStatus::Delivered, 'subtotal' => '2800.00', 'discount_total' => '100.00', 'delivery_charge' => '120.00', 'total' => '2820.00']);
        // Two lines on one order: the item-discount join must not repeat the order's totals.
        OrderItem::factory()->for($delivered)->create(['quantity' => 2, 'unit_price' => '1000.00', 'discount_amount' => '200.00', 'line_total' => '1800.00']);
        OrderItem::factory()->for($delivered)->create(['quantity' => 1, 'unit_price' => '1000.00', 'discount_amount' => '0.00', 'line_total' => '1000.00']);
        $this->order('2026-10-11 09:00', ['status' => OrderStatus::Cancelled, 'total' => '500.00']);
        $this->order('2026-10-13 09:00', ['status' => OrderStatus::Returned, 'total' => '700.00']);
        $this->order('2026-10-08 23:59:59', ['total' => '9999.00']); // before the period

        $sales = $this->report('sales', ['range' => 'last_7_days'])->viewData('sales');

        $this->assertSame([
            'orders_placed' => 4,
            'gross_value' => '5080.00',
            'excluded_orders' => 2,
            'excluded_value' => '1200.00',
            'revenue_orders' => 2,
            'gross_product_value' => '4000.00',
            'product_discounts' => '200.00',
            'product_sales' => '3800.00',
            'order_discounts' => '100.00',
            'delivery_charges' => '180.00',
            'net_sales' => '3880.00',
            'average_order_value' => '1940.00',
        ], $sales);
    }

    public function test_sales_match_the_dashboard_definition(): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1060.00']);
        $this->order('2026-10-14 11:00', ['status' => OrderStatus::Cancelled, 'total' => '400.00']);

        $dashboard = Livewire::actingAs($this->admin)->test('pages::admin.dashboard', ['range' => 'last_7_days'])->viewData('periodSales');
        $report = $this->report('sales', ['range' => 'last_7_days'])->viewData('sales');

        $this->assertSame([$dashboard['sales'], $dashboard['average_order_value'], $dashboard['revenue_orders']], [$report['net_sales'], $report['average_order_value'], $report['revenue_orders']]);
    }

    public function test_day_boundaries_use_asia_dhaka(): void
    {
        $this->order('2026-10-14 23:59:59', ['total' => '100.00']);
        $this->order('2026-10-15 00:00:00', ['total' => '200.00']);
        $this->order('2026-10-15 23:59:59', ['total' => '300.00']);

        $this->assertSame('500.00', $this->report('sales', ['range' => 'today'])->viewData('sales')['net_sales']);
        $this->assertSame('100.00', $this->report('sales', ['range' => 'yesterday'])->viewData('sales')['net_sales']);
        $this->assertSame('100.00', $this->report('sales', ['range' => 'custom', 'from' => '2026-10-14', 'to' => '2026-10-14'])->viewData('sales')['net_sales']);
    }

    public function test_the_daily_table_lists_every_day_including_empty_ones(): void
    {
        $this->order('2026-10-13 10:00', ['total' => '1060.00']);
        $this->order('2026-10-13 11:00', ['status' => OrderStatus::Cancelled, 'total' => '400.00']);

        $daily = $this->report('sales', ['range' => 'last_7_days'])->viewData('daily');

        $this->assertCount(7, $daily);
        $this->assertSame(['2026-10-09', '2026-10-15'], [$daily[0]['date'], $daily[6]['date']]);
        $this->assertSame([1, '1060.00'], [$daily[4]['orders'], $daily[4]['net_sales']]);
        $this->assertSame([0, '0.00'], [$daily[0]['orders'], $daily[0]['net_sales']]);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'end before start' => [['from' => '2026-10-10', 'to' => '2026-10-01'], 'to', 'The end date must be on or after the start date.'],
            'too long' => [['from' => '2026-01-01', 'to' => '2026-10-01'], 'to', 'Custom ranges can cover at most 92 days.'],
            'not a date' => [['from' => '2026-02-30', 'to' => '2026-10-01'], 'from', 'Enter a valid start date (YYYY-MM-DD).'],
            'missing end' => [['from' => '2026-10-01', 'to' => ''], 'to', 'Enter a valid end date (YYYY-MM-DD).'],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_custom_ranges_show_an_error_and_fall_back_to_the_default(array $dates, string $field, string $message): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1060.00']);

        $this->report('sales', ['range' => 'custom'] + $dates)
            ->assertHasErrors([$field])
            ->assertSee($message)
            ->assertSee('Showing Last 7 days')
            ->assertViewHas('sales', fn (array $sales) => $sales['net_sales'] === '1060.00');
    }

    public function test_source_and_payment_method_filters_apply_to_sales(): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1000.00', 'source' => OrderSource::Website, 'payment_method' => PaymentMethod::CashOnDelivery]);
        $this->order('2026-10-14 11:00', ['total' => '2000.00', 'source' => OrderSource::Facebook, 'payment_method' => PaymentMethod::CashOnDelivery]);
        $this->order('2026-10-14 12:00', ['total' => '4000.00', 'source' => OrderSource::Facebook, 'payment_method' => PaymentMethod::BankTransfer]);

        $this->assertSame('6000.00', $this->report('sales', ['source' => 'facebook'])->viewData('sales')['net_sales']);
        $this->assertSame('3000.00', $this->report('sales', ['paymentMethod' => 'cod'])->viewData('sales')['net_sales']);
        $this->assertSame('2000.00', $this->report('sales', ['source' => 'facebook', 'paymentMethod' => 'cod'])->viewData('sales')['net_sales']);
    }

    public function test_unknown_filter_values_are_reported_and_ignored(): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1000.00']);

        $this->report('sales', ['source' => 'tiktok', 'paymentMethod' => 'crypto'])
            ->assertHasErrors(['source', 'paymentMethod'])
            ->assertViewHas('sales', fn (array $sales) => $sales['net_sales'] === '1000.00');

        $this->report('nonsense')->assertViewHas('sales');
    }

    public function test_the_status_filter_is_not_applied_to_sales(): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1000.00', 'status' => OrderStatus::Delivered]);
        $this->order('2026-10-14 11:00', ['total' => '500.00']);

        $this->assertSame('1500.00', $this->report('sales', ['status' => 'delivered'])->viewData('sales')['net_sales']);
    }

    // --- Orders --------------------------------------------------------------------------------------

    public function test_orders_are_grouped_by_status_with_counts_and_values(): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1000.00']);
        $this->order('2026-10-14 11:00', ['total' => '1500.00']);
        $this->order('2026-10-13 10:00', ['status' => OrderStatus::Delivered, 'total' => '2000.00']);
        $this->order('2026-10-12 10:00', ['status' => OrderStatus::Cancelled, 'total' => '300.00']);
        $this->order('2026-09-01 10:00', ['total' => '9000.00']);

        $byStatus = $this->report('orders')->viewData('byStatus');
        $rows = collect($byStatus['rows'])->keyBy(fn (array $row) => $row['status']->value);

        $this->assertCount(count(OrderStatus::cases()), $byStatus['rows'], 'Every status is listed.');
        $this->assertSame([2, '2500.00'], [$rows['pending']['orders'], $rows['pending']['value']]);
        $this->assertSame([1, '2000.00'], [$rows['delivered']['orders'], $rows['delivered']['value']]);
        $this->assertSame([1, '300.00'], [$rows['cancelled']['orders'], $rows['cancelled']['value']]);
        $this->assertSame([0, '0.00'], [$rows['shipped']['orders'], $rows['shipped']['value']]);
        $this->assertSame([4, '4800.00'], [$byStatus['orders'], $byStatus['value']]);
    }

    public function test_order_filters_by_status_source_and_payment_method(): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1000.00', 'status' => OrderStatus::Delivered, 'source' => OrderSource::Phone, 'payment_method' => PaymentMethod::CashOnDelivery]);
        $this->order('2026-10-14 11:00', ['total' => '2000.00', 'status' => OrderStatus::Delivered, 'source' => OrderSource::Website, 'payment_method' => PaymentMethod::BankTransfer]);
        $this->order('2026-10-14 12:00', ['total' => '4000.00', 'status' => OrderStatus::Cancelled, 'source' => OrderSource::Phone, 'payment_method' => PaymentMethod::CashOnDelivery]);

        $this->assertSame([2, '3000.00'], array_values(array_slice($this->report('orders', ['status' => 'delivered'])->viewData('byStatus'), 1)));

        $sources = collect($this->report('orders')->viewData('bySource'))->keyBy(fn (array $row) => $row['source']->value);
        $this->assertSame([2, 1, '1000.00'], [$sources['phone']['orders'], $sources['phone']['excluded_orders'], $sources['phone']['net_sales']]);
        $this->assertSame([0, 0, '0.00'], [$sources['api']['orders'], $sources['api']['excluded_orders'], $sources['api']['net_sales']]);

        $methods = collect($this->report('orders', ['source' => 'phone'])->viewData('byPaymentMethod'))->keyBy(fn (array $row) => $row['method']->value);
        $this->assertSame([2, '1000.00'], [$methods['cod']['orders'], $methods['cod']['net_sales']]);
        $this->assertSame(0, $methods['bank_transfer']['orders']);
    }

    // --- Products ------------------------------------------------------------------------------------

    public function test_best_sellers_aggregate_revenue_order_lines_in_the_period(): void
    {
        $tee = Product::factory()->withStock(40)->create(['name' => 'Plain Tee', 'sku' => 'TEE-1']);
        $panjabi = Product::factory()->withStock(5)->create(['name' => 'Cotton Panjabi', 'sku' => 'PANJ-1']);

        $first = $this->order('2026-10-14 10:00');
        OrderItem::factory()->for($first)->for($tee)->create(['quantity' => 2, 'unit_price' => '300.00', 'line_total' => '600.00']);
        OrderItem::factory()->for($first)->for($tee)->create(['quantity' => 1, 'unit_price' => '300.00', 'line_total' => '300.00']);
        OrderItem::factory()->for($first)->for($panjabi)->create(['quantity' => 1, 'unit_price' => '1500.00', 'discount_amount' => '100.00', 'line_total' => '1400.00']);
        $second = $this->order('2026-10-13 10:00', ['status' => OrderStatus::Delivered]);
        OrderItem::factory()->for($second)->for($panjabi)->create(['quantity' => 1, 'unit_price' => '1500.00', 'line_total' => '1500.00']);
        $cancelled = $this->order('2026-10-13 11:00', ['status' => OrderStatus::Cancelled]);
        OrderItem::factory()->for($cancelled)->for($tee)->create(['quantity' => 10, 'unit_price' => '300.00', 'line_total' => '3000.00']);
        $old = $this->order('2026-09-01 10:00');
        OrderItem::factory()->for($old)->for($panjabi)->create(['quantity' => 9, 'unit_price' => '1500.00', 'line_total' => '13500.00']);

        $report = $this->report('products');
        $rows = $report->viewData('bestSellers')->getCollection();

        $this->assertSame(['TEE-1', 'PANJ-1'], $rows->pluck('sku')->all(), 'Most units first.');
        $this->assertSame([3, '900.00', 1], [(int) $rows[0]->units, bcadd($rows[0]->sales_value, '0', 2), (int) $rows[0]->orders], 'Two lines in one order count as one order.');
        $this->assertSame([2, '2900.00', 2], [(int) $rows[1]->units, bcadd($rows[1]->sales_value, '0', 2), (int) $rows[1]->orders]);
        $this->assertSame(['products' => 2, 'units' => 5, 'sales_value' => '3800.00'], $report->viewData('productSummary'));
    }

    public function test_best_sellers_are_paginated(): void
    {
        $order = $this->order('2026-10-14 10:00');
        Product::factory()->count(17)->create()->each(fn (Product $product, int $index) => OrderItem::factory()->for($order)->for($product)->create(['quantity' => $index + 1]));

        $page = $this->report('products')->viewData('bestSellers');
        $this->assertSame([17, 15, 2], [$page->total(), $page->count(), $page->lastPage()]);
        $this->assertSame(17, (int) $page->getCollection()->first()->units);
    }

    public function test_the_restock_list_uses_the_dashboard_rule(): void
    {
        $out = Product::factory()->outOfStock()->create(['name' => 'Gone Tee']);
        $low = Product::factory()->withStock(2)->create(['name' => 'Low Saree', 'low_stock_threshold' => 3]);
        Product::factory()->withStock(10)->create(['name' => 'Plenty Panjabi', 'low_stock_threshold' => 3]);
        Product::factory()->archived()->outOfStock()->create(['name' => 'Archived Item']);
        $manual = Product::factory()->create(['name' => 'Untracked Shawl', 'manage_stock' => false, 'stock_status' => StockStatus::OutOfStock]);

        $restock = $this->report('products')->viewData('restock');

        $this->assertEqualsCanonicalizing([$out->id, $manual->id, $low->id], $restock->pluck('id')->all());
        $this->assertSame(StockStatus::OutOfStock, $restock->first()->stock_status, 'Out of stock first.');
        $this->assertNotContains('Archived Item', $restock->pluck('name')->all());
    }

    // --- Payments ------------------------------------------------------------------------------------

    public function test_collections_count_completed_ledger_rows_by_paid_date_once(): void
    {
        $order = $this->order('2026-09-20 10:00', ['total' => '3000.00']);
        Payment::factory()->for($order)->completed()->create(['amount' => '1000.00', 'method' => PaymentMethod::CashOnDelivery, 'paid_at' => CarbonImmutable::parse('2026-10-14 10:00', 'Asia/Dhaka')]);
        Payment::factory()->for($order)->completed()->create(['amount' => '500.00', 'method' => PaymentMethod::BankTransfer, 'paid_at' => CarbonImmutable::parse('2026-10-15 23:59:59', 'Asia/Dhaka')]);
        Payment::factory()->for($order)->completed()->refund()->create(['amount' => '200.00', 'method' => PaymentMethod::BankTransfer, 'paid_at' => CarbonImmutable::parse('2026-10-12 10:00', 'Asia/Dhaka')]);
        Payment::factory()->for($order)->completed()->create(['amount' => '700.00', 'paid_at' => CarbonImmutable::parse('2026-10-08 23:59:59', 'Asia/Dhaka')]); // before the period
        // Incomplete rows never count, even if a paid_at falls inside the period.
        Payment::factory()->for($order)->create(['amount' => '900.00', 'status' => PaymentTransactionStatus::Pending, 'created_at' => CarbonImmutable::parse('2026-10-13', 'Asia/Dhaka'), 'paid_at' => CarbonImmutable::parse('2026-10-13', 'Asia/Dhaka')]);
        Payment::factory()->for($order)->create(['amount' => '800.00', 'status' => PaymentTransactionStatus::Failed, 'created_at' => CarbonImmutable::parse('2026-10-13', 'Asia/Dhaka'), 'paid_at' => CarbonImmutable::parse('2026-10-13', 'Asia/Dhaka')]);

        $report = $this->report('payments');
        $collections = $report->viewData('collections');
        $methods = collect($collections['by_method'])->keyBy(fn (array $row) => $row['method']->value);

        $this->assertSame(['1500.00', '200.00', '1300.00', 2, 1], [$collections['payments'], $collections['refunds'], $collections['net'], $collections['payment_count'], $collections['refund_count']]);
        $this->assertSame(['1000.00', '0.00', '1000.00'], [$methods['cod']['payments'], $methods['cod']['refunds'], $methods['cod']['net']]);
        $this->assertSame(['500.00', '200.00', '300.00'], [$methods['bank_transfer']['payments'], $methods['bank_transfer']['refunds'], $methods['bank_transfer']['net']]);
        $this->assertSame(['pending' => 1, 'failed' => 1], $report->viewData('attempts'));
    }

    public function test_balances_separate_billed_received_and_outstanding(): void
    {
        $this->order('2026-10-14 10:00', ['total' => '1000.00', 'amount_paid' => '0.00', 'payment_status' => PaymentStatus::Pending]);
        $this->order('2026-10-14 11:00', ['total' => '2000.00', 'amount_paid' => '500.00', 'payment_status' => PaymentStatus::PartiallyPaid]);
        $this->order('2026-10-14 12:00', ['total' => '1500.00', 'amount_paid' => '1500.00', 'payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Delivered]);
        $this->order('2026-10-13 12:00', ['total' => '800.00', 'amount_paid' => '900.00', 'payment_status' => PaymentStatus::Paid]); // overpaid: nothing outstanding
        $this->order('2026-10-13 13:00', ['total' => '400.00', 'amount_paid' => '0.00', 'payment_status' => PaymentStatus::Failed]);
        $this->order('2026-10-12 10:00', ['total' => '600.00', 'amount_paid' => '600.00', 'payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Cancelled]);
        $this->order('2026-10-12 11:00', ['total' => '300.00', 'amount_paid' => '0.00', 'payment_status' => PaymentStatus::Refunded, 'status' => OrderStatus::Returned]);

        $this->assertSame([
            'revenue_orders' => 5,
            'billed' => '5700.00',
            'received' => '2900.00',
            'outstanding' => '2900.00',
            'unpaid_orders' => 2,
            'unpaid_due' => '1400.00',
            'partial_orders' => 1,
            'partial_due' => '1500.00',
            'paid_orders' => 2,
            'refunded_orders' => 1,
            'excluded_paid_orders' => 1,
            'excluded_paid_amount' => '600.00',
        ], $this->report('payments')->viewData('balances'));
    }

    // --- Customers -----------------------------------------------------------------------------------

    public function test_customers_are_new_or_returning_by_their_first_revenue_order(): void
    {
        $returning = Customer::factory()->create(['name' => 'Rahima Akter', 'phone' => '01712345678', 'email' => 'rahima@example.com']);
        $new = Customer::factory()->create(['name' => 'Karim Uddin', 'phone' => '01812345678']);
        $cancelledOnly = Customer::factory()->create(['name' => 'Cancel Only']);
        $firstWasCancelled = Customer::factory()->create(['name' => 'Second Try']);

        $this->order('2026-08-01 10:00', ['customer_id' => $returning->id, 'total' => '5000.00', 'status' => OrderStatus::Delivered]);
        $this->order('2026-10-14 10:00', ['customer_id' => $returning->id, 'total' => '1000.00']);
        $this->order('2026-10-13 10:00', ['customer_id' => $new->id, 'total' => '3000.00']);
        $this->order('2026-10-14 11:00', ['customer_id' => $new->id, 'total' => '500.00', 'status' => OrderStatus::Returned]);
        $this->order('2026-10-14 12:00', ['customer_id' => $cancelledOnly->id, 'total' => '700.00', 'status' => OrderStatus::Cancelled]);
        $this->order('2026-07-01 10:00', ['customer_id' => $firstWasCancelled->id, 'total' => '100.00', 'status' => OrderStatus::Cancelled]);
        $this->order('2026-10-12 10:00', ['customer_id' => $firstWasCancelled->id, 'total' => '200.00']);

        $report = $this->report('customers');

        $this->assertSame(['customers' => 3, 'new' => 2, 'returning' => 1, 'orders' => 3, 'spend' => '4200.00'], $report->viewData('customerSummary'));

        $rows = collect($report->viewData('customerRows')->items())->keyBy(fn (array $row) => $row['customer']->name);
        $this->assertSame(['Karim Uddin', 'Rahima Akter', 'Second Try'], $rows->keys()->all(), 'Highest period spend first.');
        $this->assertSame([1, '1000.00', 2, '6000.00', false], [$rows['Rahima Akter']['period_orders'], $rows['Rahima Akter']['period_spend'], $rows['Rahima Akter']['lifetime_orders'], $rows['Rahima Akter']['lifetime_spend'], $rows['Rahima Akter']['is_new']]);
        $this->assertSame([1, '3000.00', true], [$rows['Karim Uddin']['lifetime_orders'], $rows['Karim Uddin']['lifetime_spend'], $rows['Karim Uddin']['is_new']]);
        $this->assertTrue($rows['Second Try']['is_new'], 'A cancelled earlier order does not make a customer returning.');

        $report->assertSee('017•••••678')->assertDontSee('01712345678')->assertDontSee('rahima@example.com')->assertDontSee('Cancel Only');
    }

    public function test_customer_rows_are_paginated(): void
    {
        Customer::factory()->count(16)->create()->each(fn (Customer $customer, int $index) => $this->order('2026-10-14 10:00', ['customer_id' => $customer->id, 'total' => (string) (100 + $index)]));

        $page = $this->report('customers')->viewData('customerRows');

        $this->assertSame([16, 15, 2], [$page->total(), $page->count(), $page->lastPage()]);
        $this->assertSame('115.00', $page->items()[0]['period_spend']);
    }

    // --- Empty data, query budget, layout ----------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function reports(): array
    {
        return [
            'sales' => ['sales', 'How net sales add up'],
            'orders' => ['orders', 'By status'],
            'products' => ['products', 'No products were sold in this period.'],
            'payments' => ['payments', 'Collected by method'],
            'customers' => ['customers', 'No customers bought in this period.'],
        ];
    }

    #[DataProvider('reports')]
    public function test_every_report_renders_with_no_data(string $report, string $expected): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports', ['report' => $report]))->assertOk()->assertSee($expected);
    }

    #[DataProvider('reports')]
    public function test_each_report_runs_a_fixed_number_of_queries(string $report): void
    {
        $this->seedOrders(2);
        $small = $this->countQueries($report);

        $this->seedOrders(20);
        $large = $this->countQueries($report);

        $this->assertSame($small, $large, "{$report}: query count must not grow with the data.");
    }

    private function seedOrders(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $order = $this->order('2026-10-14 10:00', ['total' => '1000.00', 'customer_id' => Customer::factory()->create()->id]);
            OrderItem::factory()->for($order)->create();
            Payment::factory()->for($order)->completed()->create(['paid_at' => CarbonImmutable::parse('2026-10-14 11:00', 'Asia/Dhaka')]);
        }
    }

    private function countQueries(string $report): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.reports', ['report' => $report]))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_page_is_admin_only_and_has_no_storefront_tracking(): void
    {
        $html = (string) $this->actingAs($this->admin)->get(route('admin.reports'))->getContent();

        $this->assertStringNotContainsString('googletagmanager', $html);
        $this->assertStringNotContainsString('consent-banner-title', $html);
        $this->assertStringContainsString('All dates and times are Asia/Dhaka', $html);
    }
}
