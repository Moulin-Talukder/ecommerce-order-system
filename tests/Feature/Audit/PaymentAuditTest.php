<?php

namespace Tests\Feature\Audit;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordPayment;
use App\Actions\Orders\RecordRefund;
use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRedactor;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->admin = User::factory()->admin()->create();
        $product = Product::factory()->withStock(10)->create(['selling_price' => '1500.00']);

        // Total 3 × 1500 + 60 delivery = 4560.00
        $this->order = app(CreateOrder::class)->handle([
            'customer_name' => 'Rahima Akter', 'customer_phone' => '01712345678', 'shipping_address' => 'House 12, Dhanmondi',
            'items' => [['product_id' => $product->id, 'quantity' => 3]], 'delivery_charge' => '60',
            'payment_method' => PaymentMethod::CashOnDelivery->value, 'source' => OrderSource::Phone->value,
        ], $this->manager);
    }

    /**
     * Audit rows written after setup (the order and customer creation rows are excluded).
     *
     * @return Collection<int, AuditLog>
     */
    private function paymentPhaseLogs(): Collection
    {
        return AuditLog::query()->whereNotIn('event', [AuditEvent::OrderCreated, AuditEvent::CustomerCreated])->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function pay(array $input = []): TestResponse
    {
        return $this->actingAs($this->manager)->post(route('admin.orders.payments.store', $this->order), array_merge([
            'amount' => '4560', 'method' => 'bank_transfer', 'status' => 'completed',
            'transaction_reference' => 'TRX-889100234', 'note' => 'Paid by the customer\'s brother Karim',
        ], $input));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function refund(array $input = [], ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->admin)->post(route('admin.orders.refunds.store', $this->order), array_merge([
            'amount' => '1500', 'method' => 'bank_transfer', 'transaction_reference' => 'RFD-55500771',
            'note' => 'Damaged collar, customer sent photos on WhatsApp',
        ], $input));
    }

    private function rawAuditRows(): string
    {
        return (string) json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);
    }

    private function failAuditWrites(): void
    {
        $logger = Mockery::mock(AuditLogger::class, [app(AuditRedactor::class)])->makePartial();
        $logger->shouldReceive('record')->andThrow(new RuntimeException('audit store unavailable'));
        $this->instance(AuditLogger::class, $logger);
    }

    // --- Payments ------------------------------------------------------------------------------

    public function test_a_payment_records_one_masked_payment_recorded_event(): void
    {
        $this->pay()->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();
        $log = $this->paymentPhaseLogs()->sole();
        $this->assertSame([AuditEvent::PaymentRecorded, AuditOrigin::Admin, $this->manager->id], [$log->event, $log->origin, $log->actor_id]);
        $this->assertSame(['payment', $payment->id, 'Payment #'.$payment->id], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertEquals([
            'order_id' => $this->order->id, 'type' => 'payment', 'method' => 'bank_transfer', 'status' => 'completed',
            'amount' => '4560.00', 'currency' => 'BDT', 'transaction_reference' => '••••0234',
            'paid_at' => $payment->paid_at->format(DATE_ATOM), 'note' => AuditRedactor::CHANGED,
        ], $log->new_values);
        $this->assertEquals([
            'order_number' => $this->order->order_number,
            'order_payment_status' => ['from' => 'pending', 'to' => 'paid'],
            'order_amount_paid' => ['from' => '0.00', 'to' => '4560.00'],
        ], $log->context);

        $this->order->refresh();
        $this->assertSame([PaymentStatus::Paid, '4560.00'], [$this->order->payment_status, $this->order->amount_paid], 'The ledger result is unchanged.');
        $this->assertSensitivePaymentDataAbsent();
    }

    public function test_a_failed_payment_attempt_is_recorded_with_its_status(): void
    {
        $this->pay(['status' => 'failed', 'amount' => '4560', 'transaction_reference' => ''])->assertSessionHasNoErrors();

        $log = $this->paymentPhaseLogs()->sole();
        $this->assertSame(['failed', null, null], [$log->new_values['status'], $log->new_values['paid_at'], $log->new_values['transaction_reference']]);
        $this->assertEquals(['from' => 'pending', 'to' => 'failed'], $log->context['order_payment_status']);
    }

    public function test_refused_payments_record_nothing(): void
    {
        $route = route('admin.orders.payments.store', $this->order);
        $valid = ['amount' => '100', 'method' => 'cod', 'status' => 'completed'];

        $this->actingAs($this->manager)->postJson($route, ['amount' => '0'] + $valid)->assertJsonValidationErrors('amount');
        $this->postJson($route, ['method' => 'crypto'] + $valid)->assertJsonValidationErrors('method');
        $this->postJson($route, ['amount' => '9999'] + $valid)->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'more than the amount due'));
        $this->actingAs(User::factory()->create())->postJson($route, $valid)->assertForbidden();

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, $this->paymentPhaseLogs()->count());
    }

    public function test_repeating_a_full_payment_is_refused_and_recorded_once(): void
    {
        $this->pay()->assertSessionHasNoErrors();
        $this->pay()->assertSessionHas('error');

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $this->paymentPhaseLogs()->count());
    }

    // --- Refunds -------------------------------------------------------------------------------

    public function test_a_refund_records_one_payment_refunded_event_without_the_reason(): void
    {
        $this->pay();

        $this->refund()->assertSessionHasNoErrors();

        $refund = Payment::query()->where('type', 'refund')->sole();
        $log = $this->paymentPhaseLogs()->where('event', AuditEvent::PaymentRefunded)->sole();
        $this->assertSame([AuditOrigin::Admin, $this->admin->id], [$log->origin, $log->actor_id]);
        $this->assertSame(['payment', $refund->id, 'Refund #'.$refund->id], [$log->subject_type, $log->subject_id, $log->subject_label]);
        $this->assertSame(['refund', '1500.00', '••••0771', AuditRedactor::CHANGED], [$log->new_values['type'], $log->new_values['amount'], $log->new_values['transaction_reference'], $log->new_values['note']]);
        $this->assertEquals([
            'order_number' => $this->order->order_number,
            'order_payment_status' => ['from' => 'paid', 'to' => 'partially_refunded'],
            'order_amount_paid' => ['from' => '4560.00', 'to' => '3060.00'],
        ], $log->context);

        $this->order->refresh();
        $this->assertSame([PaymentStatus::PartiallyRefunded, '3060.00'], [$this->order->payment_status, $this->order->amount_paid]);
        $this->assertSensitivePaymentDataAbsent();
    }

    public function test_refused_refunds_record_nothing(): void
    {
        $this->pay();

        $route = route('admin.orders.refunds.store', $this->order);
        $valid = ['amount' => '100', 'method' => 'bank_transfer', 'note' => 'Reason'];

        $this->actingAs($this->admin)->postJson($route, ['note' => ''] + $valid)->assertJsonValidationErrors('note');
        $this->postJson($route, ['amount' => '9999'] + $valid)->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'more than the amount paid'));
        $this->actingAs($this->manager)->postJson($route, $valid)->assertForbidden();

        $this->assertSame(0, Payment::query()->where('type', 'refund')->count());
        $this->assertSame([AuditEvent::PaymentRecorded], $this->paymentPhaseLogs()->pluck('event')->all());
    }

    public function test_refunding_more_than_remains_after_a_full_refund_is_refused_and_recorded_once(): void
    {
        $this->pay();

        $this->refund(['amount' => '4560'])->assertSessionHasNoErrors();
        $this->refund(['amount' => '4560'])->assertSessionHas('error');

        $this->assertSame(1, Payment::query()->where('type', 'refund')->count());
        $this->assertSame(1, $this->paymentPhaseLogs()->where('event', AuditEvent::PaymentRefunded)->count());
        $this->assertSame(PaymentStatus::Refunded, $this->order->refresh()->payment_status);
    }

    // --- Fail-closed ----------------------------------------------------------------------------

    /**
     * @return array<string, array{bool, Closure(Order, User): mixed}>
     */
    public static function paymentWrites(): array
    {
        return [
            'payment' => [false, fn (Order $order, User $actor) => app(RecordPayment::class)->handle($order, '1000', PaymentMethod::BankTransfer, PaymentTransactionStatus::Completed, $actor, 'TRX-1', 'Note')],
            'failed attempt' => [false, fn (Order $order, User $actor) => app(RecordPayment::class)->handle($order, '1000', PaymentMethod::BankTransfer, PaymentTransactionStatus::Failed, $actor)],
            'refund' => [true, fn (Order $order, User $actor) => app(RecordRefund::class)->handle($order, '500', PaymentMethod::BankTransfer, $actor, 'RFD-1', 'Reason')],
        ];
    }

    #[DataProvider('paymentWrites')]
    public function test_a_failed_audit_write_rolls_back_the_payment_and_the_order_summary(bool $paidFirst, Closure $write): void
    {
        if ($paidFirst) {
            app(RecordPayment::class)->handle($this->order, '4560', PaymentMethod::BankTransfer, user: $this->manager);
        }
        $before = [
            DB::table('payments')->orderBy('id')->get(),
            DB::table('orders')->where('id', $this->order->id)->first(['payment_status', 'amount_paid']),
            AuditLog::query()->count(),
        ];
        $this->failAuditWrites();

        try {
            $write($this->order, $this->admin);
            $this->fail('The audit failure must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store unavailable', $exception->getMessage());
        }

        $this->assertEquals($before, [
            DB::table('payments')->orderBy('id')->get(),
            DB::table('orders')->where('id', $this->order->id)->first(['payment_status', 'amount_paid']),
            AuditLog::query()->count(),
        ]);
    }

    // --- Consistency ----------------------------------------------------------------------------

    public function test_payments_and_refunds_create_no_order_or_stock_audit_events_and_the_timeline_still_shows_them(): void
    {
        $orderEventsBefore = AuditLog::query()->where('subject_type', 'order')->count();

        $this->pay(['amount' => '4000']);
        $this->pay(['amount' => '560']);
        $this->refund(['amount' => '500']);

        $this->assertSame(
            [AuditEvent::PaymentRecorded, AuditEvent::PaymentRecorded, AuditEvent::PaymentRefunded],
            $this->paymentPhaseLogs()->pluck('event')->all(),
        );
        $this->assertSame($orderEventsBefore, AuditLog::query()->where('subject_type', 'order')->count());
        $this->assertSame(0, AuditLog::query()->where('event', AuditEvent::StockAdjusted)->count());
        $this->assertSame(Payment::query()->orderBy('id')->pluck('id')->all(), $this->paymentPhaseLogs()->pluck('subject_id')->all(), 'Each event points at its own payment row.');

        $this->actingAs($this->manager)->get(route('admin.orders.show', $this->order))
            ->assertOk()
            ->assertSee('Payment of')
            ->assertSee('Refund of');
        $this->assertSame([PaymentStatus::PartiallyRefunded, '4060.00'], [$this->order->refresh()->payment_status, $this->order->amount_paid]);
    }

    private function assertSensitivePaymentDataAbsent(): void
    {
        $raw = $this->rawAuditRows();

        foreach (['TRX-889100234', '889100234', 'RFD-55500771', 'brother Karim', 'Damaged collar', 'WhatsApp', 'Rahima', '01712345678', 'Dhanmondi', 'gateway'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $raw, "[{$sensitive}] reached the audit rows.");
        }
    }
}
