<?php

namespace Tests\Feature\Audit;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\UpdateOrderDetails;
use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\Orders\OrderException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cccccccccccccccccccccccccccccccccccccccc';

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->product = Product::factory()->withStock(10)->create(['selling_price' => '1500.00']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderData(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Rahima Akter', 'customer_phone' => '01712345678', 'customer_alternate_phone' => '',
            'customer_email' => 'rahima.akter@example.test', 'shipping_address' => 'House 12, Road 5, Dhanmondi',
            'shipping_area' => 'Mirpur', 'shipping_city' => 'Dhaka', 'shipping_postal_code' => '1216',
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]], 'delivery_charge' => '60',
            'discount_total' => '', 'payment_method' => PaymentMethod::CashOnDelivery->value, 'source' => OrderSource::Phone->value,
            'customer_note' => 'Leave with the guard', 'admin_note' => 'VIP caller',
        ], $overrides);
    }

    private function placeOrder(): Order
    {
        return app(CreateOrder::class)->handle($this->orderData(), $this->manager);
    }

    /**
     * Audit rows written after the given id (earlier setup rows are excluded).
     *
     * @return Collection<int, AuditLog>
     */
    private function logsAfter(int $id): Collection
    {
        return AuditLog::query()->where('id', '>', $id)->orderBy('id')->get();
    }

    private function lastLogId(): int
    {
        return (int) AuditLog::query()->max('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function checkout(array $overrides = []): TestResponse
    {
        return $this->withSession(['storefront.cart' => [$this->product->id => 2], 'storefront.checkout_tokens' => [self::TOKEN]])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.88'])
            ->withHeader('User-Agent', 'GuestBrowser/6.0')
            ->withHeader('Authorization', 'Bearer guest-secret-token-456')
            ->withCookie('tracking_consent', 'cookie-value-456')
            ->post(route('storefront.checkout.store'), array_merge([
                'checkout_token' => self::TOKEN, 'name' => 'Rahima Akter', 'phone' => '01712345678', 'alternate_phone' => '',
                'email' => 'rahima.akter@example.test', 'address' => 'House 12, Road 5, Dhanmondi', 'area' => 'Mirpur',
                'city' => 'Dhaka', 'delivery_zone' => 'inside_dhaka', 'note' => 'Leave with the guard', 'website' => '',
            ], $overrides));
    }

    private function failAuditWrites(?AuditEvent $only = null): void
    {
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->withArgs(fn (AuditEvent $event) => $only === null || $event === $only)->andThrow(new RuntimeException('audit store unavailable'));
        $logger->shouldReceive('record')->withArgs(fn (AuditEvent $event) => $only !== null && $event !== $only)->passthru();
        $this->instance(AuditLogger::class, $logger);
    }

    private function rawAuditRows(): string
    {
        return (string) json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);
    }

    // --- Creation ----------------------------------------------------------------------------

    public function test_an_admin_order_records_one_order_created_with_a_masked_snapshot(): void
    {
        $this->actingAs($this->manager)->post(route('admin.orders.store'), $this->orderData())->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame([AuditEvent::CustomerCreated, AuditEvent::OrderCreated], AuditLog::query()->orderBy('id')->pluck('event')->all());

        $log = AuditLog::query()->where('event', AuditEvent::OrderCreated)->sole();
        $this->assertSame([AuditOrigin::Admin, $this->manager->id], [$log->origin, $log->actor_id]);
        $this->assertSame(['order', $order->id, $order->order_number], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertEquals([
            'order_number' => $order->order_number, 'customer_id' => $order->customer_id, 'customer_name' => 'R••• A•••',
            'customer_phone' => '••••••••678', 'customer_alternate_phone' => null, 'customer_email' => 'r•••@example.test',
            'shipping_address' => AuditRedactor::CHANGED, 'customer_note' => AuditRedactor::CHANGED, 'admin_note' => AuditRedactor::CHANGED,
            'currency' => 'BDT', 'subtotal' => '4500.00', 'discount_total' => '0.00', 'delivery_charge' => '60.00', 'total' => '4560.00',
            'amount_paid' => '0.00', 'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'pending', 'source' => 'phone',
            'shipping_city' => AuditRedactor::CHANGED, 'shipping_area' => AuditRedactor::CHANGED, 'shipping_postal_code' => AuditRedactor::CHANGED,
        ], $log->new_values);
        $this->assertEquals(['item_count' => 1, 'items' => [['product_id' => $this->product->id, 'quantity' => 3]]], $log->context);
        $this->assertSame(7, $this->product->refresh()->stock_quantity, 'Stock deduction is unchanged.');
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::StockAdjusted)->count());
        $this->assertPersonalDataAbsent();
    }

    public function test_a_guest_checkout_for_a_new_customer_records_the_customer_and_the_order(): void
    {
        $this->checkout()->assertRedirect();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([AuditEvent::CustomerCreated, AuditEvent::OrderCreated], $logs->pluck('event')->all());
        foreach ($logs as $log) {
            $this->assertSame([AuditOrigin::Storefront, null, '203.0.113.88', 'GuestBrowser/6.0'], [$log->origin, $log->actor_id, $log->ip_address, $log->user_agent]);
        }
        $this->assertSame('website', $logs[1]->new_values['source']);

        $raw = $this->rawAuditRows();
        foreach (['guest-secret-token', 'cookie-value-456', self::TOKEN, $this->app['session.store']->getId()] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertPersonalDataAbsent();
    }

    public function test_a_guest_checkout_for_an_existing_customer_records_only_the_order(): void
    {
        Customer::factory()->create(['phone' => '01712345678']);

        $this->checkout()->assertRedirect();

        $this->assertSame([AuditEvent::OrderCreated], AuditLog::query()->pluck('event')->all());
    }

    public function test_a_refused_order_leaves_no_audit_rows(): void
    {
        $this->actingAs($this->manager)->post(route('admin.orders.store'), $this->orderData(['items' => [['product_id' => $this->product->id, 'quantity' => 11]]]));
        $this->actingAs(User::factory()->create())->post(route('admin.orders.store'), $this->orderData())->assertForbidden();
        $this->checkout(['name' => ''])->assertSessionHasErrors('name');

        $this->assertSame([0, 0, 0], [Order::query()->count(), Customer::query()->count(), AuditLog::query()->count()]);
    }

    public function test_a_failed_order_audit_rolls_back_the_whole_admin_order(): void
    {
        $this->failAuditWrites(AuditEvent::OrderCreated);

        try {
            $this->placeOrder();
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertSame([0, 0, 0, 0], [Order::query()->count(), Customer::query()->count(), AuditLog::query()->count(), StockMovement::query()->count()]);
        $this->assertSame(10, $this->product->refresh()->stock_quantity);
    }

    public function test_a_failed_order_audit_blocks_a_guest_checkout_for_an_existing_customer(): void
    {
        Customer::factory()->create(['phone' => '01712345678']);
        $this->failAuditWrites(AuditEvent::OrderCreated);

        $this->checkout()->assertServerError();

        $this->assertSame([0, 0], [Order::query()->count(), AuditLog::query()->count()]);
        $this->assertSame(10, $this->product->refresh()->stock_quantity);
        $this->assertSame([$this->product->id => 2], $this->app['session.store']->get('storefront.cart'), 'The cart is kept.');
    }

    // --- Detail updates ------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_merge(collect($this->orderData())->only([...UpdateOrderDetails::DELIVERY_FIELDS, 'admin_note'])->all(), $overrides);
    }

    public function test_a_detail_change_records_only_the_changed_fields_masked(): void
    {
        $order = $this->placeOrder();
        $start = $this->lastLogId();

        $this->actingAs($this->manager)->put(route('admin.orders.update', $order), $this->details(['customer_phone' => '01755555999', 'shipping_city' => 'Chattogram', 'admin_note' => 'Call first']))
            ->assertSessionHasNoErrors();

        $log = $this->logsAfter($start)->sole();
        $this->assertSame([AuditEvent::OrderUpdated, $this->manager->id, $order->id], [$log->event, $log->actor_id, $log->subject_id]);
        $this->assertEquals(['customer_phone' => '••••••••678', 'shipping_city' => AuditRedactor::CHANGED, 'admin_note' => AuditRedactor::CHANGED], $log->old_values);
        $this->assertEquals(['customer_phone' => '••••••••999', 'shipping_city' => AuditRedactor::CHANGED, 'admin_note' => AuditRedactor::CHANGED], $log->new_values);
        $this->assertStringNotContainsString('Chattogram', $this->rawAuditRows());
        $this->assertStringNotContainsString('Call first', $this->rawAuditRows());
        $this->assertPersonalDataAbsent();
    }

    public function test_unchanged_or_refused_detail_updates_record_nothing(): void
    {
        $order = $this->placeOrder();
        $start = $this->lastLogId();

        $this->actingAs($this->manager)->put(route('admin.orders.update', $order), $this->details(['customer_phone' => '+880 1712-345678']))->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create())->put(route('admin.orders.update', $order), $this->details(['admin_note' => 'x']))->assertForbidden();

        $order->forceFill(['status' => OrderStatus::Shipped])->save();
        try {
            app(UpdateOrderDetails::class)->handle($order, $this->details(['shipping_city' => 'Sylhet']), $this->manager);
            $this->fail('Delivery details of a shipped order must not change.');
        } catch (OrderException) {
        }

        $this->assertSame(0, $this->logsAfter($start)->count());
    }

    // --- Status changes ---------------------------------------------------------------------------

    public function test_an_ordinary_transition_records_one_status_change_consistent_with_history(): void
    {
        $order = $this->placeOrder();
        $start = $this->lastLogId();

        $this->actingAs($this->manager)->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertSessionHasNoErrors();

        $log = $this->logsAfter($start)->sole();
        $history = $order->statusHistories()->reorder('id', 'desc')->first();
        $this->assertSame([AuditEvent::OrderStatusChanged, $this->manager->id, $order->id], [$log->event, $log->actor_id, $log->subject_id]);
        $this->assertSame([['status' => 'pending'], ['status' => 'confirmed']], [$log->old_values, $log->new_values]);
        $this->assertSame([OrderStatus::Pending, OrderStatus::Confirmed, $this->manager->id], [$history->old_status, $history->new_status, $history->changed_by]);
        $this->assertNull($log->context);
    }

    public function test_cancelling_records_only_order_cancelled_with_the_restored_stock(): void
    {
        $order = $this->placeOrder();
        $start = $this->lastLogId();

        $this->actingAs($this->manager)->patch(route('admin.orders.status', $order), ['status' => 'cancelled', 'note' => 'Customer changed mind at 01712345678'])
            ->assertSessionHasNoErrors();
        $this->patch(route('admin.orders.status', $order), ['status' => 'cancelled', 'note' => 'Again']);

        $log = $this->logsAfter($start)->sole();
        $this->assertSame(AuditEvent::OrderCancelled, $log->event);
        $this->assertSame([['status' => 'pending'], ['status' => 'cancelled']], [$log->old_values, $log->new_values]);
        $this->assertEquals(['note' => AuditRedactor::CHANGED, 'restocked' => [['product_id' => $this->product->id, 'quantity' => 3]]], $log->context);
        $this->assertSame(10, $this->product->refresh()->stock_quantity, 'Stock restored exactly once.');
        $this->assertSame(['sale', 'cancellation'], StockMovement::query()->orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame(0, AuditLog::query()->whereIn('event', [AuditEvent::OrderStatusChanged, AuditEvent::StockAdjusted])->count());
        $this->assertStringNotContainsString('changed mind', $this->rawAuditRows());
    }

    public function test_a_return_records_only_order_returned_after_the_earlier_transitions(): void
    {
        $order = $this->placeOrder();
        $start = $this->lastLogId();
        $this->actingAs($this->manager);

        foreach (['confirmed', 'processing', 'shipped'] as $status) {
            $this->patch(route('admin.orders.status', $order), ['status' => $status])->assertSessionHasNoErrors();
        }
        $this->patch(route('admin.orders.status', $order), ['status' => 'returned', 'note' => 'Wrong size'])->assertSessionHasNoErrors();

        $logs = $this->logsAfter($start);
        $this->assertSame([AuditEvent::OrderStatusChanged, AuditEvent::OrderStatusChanged, AuditEvent::OrderStatusChanged, AuditEvent::OrderReturned], $logs->pluck('event')->all());
        $this->assertSame([['status' => 'shipped'], ['status' => 'returned']], [$logs[3]->old_values, $logs[3]->new_values]);
        $this->assertEquals([['product_id' => $this->product->id, 'quantity' => 3]], $logs[3]->context['restocked']);
        $this->assertSame(10, $this->product->refresh()->stock_quantity);

        $histories = $order->statusHistories()->reorder('id')->get()->skip(1)->values();
        $this->assertSame(
            $histories->map(fn ($history) => [$history->old_status->value, $history->new_status->value])->all(),
            $logs->map(fn (AuditLog $log) => [$log->old_values['status'], $log->new_values['status']])->all(),
            'One audit row per status history row, with the same transition.',
        );
    }

    public function test_invalid_or_unauthorised_transitions_record_nothing(): void
    {
        $order = $this->placeOrder();
        $start = $this->lastLogId();

        $this->actingAs($this->manager)->patch(route('admin.orders.status', $order), ['status' => 'delivered']);
        $this->patch(route('admin.orders.status', $order), ['status' => 'pending']);
        $this->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])->assertRedirect(); // refused: a reason is required
        $this->actingAs(User::factory()->create())->patch(route('admin.orders.status', $order), ['status' => 'confirmed'])->assertForbidden();

        $this->assertSame(0, $this->logsAfter($start)->count());
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(1, $order->statusHistories()->count());
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function failedTransitions(): array
    {
        return [
            'ordinary transition' => [[], 'confirmed'],
            'cancellation' => [[], 'cancelled'],
            'return' => [['confirmed', 'processing', 'shipped'], 'returned'],
        ];
    }

    /**
     * @param  list<string>  $path
     */
    #[DataProvider('failedTransitions')]
    public function test_a_failed_audit_write_rolls_back_the_status_history_and_stock(array $path, string $target): void
    {
        $order = $this->placeOrder();
        foreach ($path as $status) {
            app(ChangeOrderStatus::class)->handle($order, OrderStatus::from($status), $this->manager);
        }
        $before = [$order->refresh()->status, $order->statusHistories()->count(), $this->product->refresh()->stock_quantity, StockMovement::query()->count(), AuditLog::query()->count()];
        $this->failAuditWrites();

        try {
            app(ChangeOrderStatus::class)->handle($order, OrderStatus::from($target), $this->manager, 'Reason');
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertSame($before, [$order->refresh()->status, $order->statusHistories()->count(), $this->product->refresh()->stock_quantity, StockMovement::query()->count(), AuditLog::query()->count()]);
    }

    public function test_a_failed_audit_write_rolls_back_a_detail_update(): void
    {
        $order = $this->placeOrder();
        $before = DB::table('orders')->where('id', $order->id)->first();
        $this->failAuditWrites();

        try {
            app(UpdateOrderDetails::class)->handle($order, $this->details(['admin_note' => 'Changed']), $this->manager);
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException) {
        }

        $this->assertEquals($before, DB::table('orders')->where('id', $order->id)->first());
    }

    /**
     * No full name, phone, email, address, area, city, postal code or note
     * text may appear anywhere in the stored audit rows.
     */
    private function assertPersonalDataAbsent(): void
    {
        $raw = $this->rawAuditRows();

        foreach (['Rahima', 'Akter', '01712345678', '1712345678', 'rahima.akter', 'House 12', 'Dhanmondi', 'Mirpur', 'Dhaka', '1216', 'guard', 'VIP caller'] as $personal) {
            $this->assertStringNotContainsString($personal, $raw, "[{$personal}] reached the audit rows.");
        }
    }
}
