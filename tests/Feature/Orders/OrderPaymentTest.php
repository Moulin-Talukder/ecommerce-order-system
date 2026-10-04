<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\RecordPayment;
use App\Actions\Orders\RecordRefund;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Exceptions\Orders\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\PaymentLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->order = Order::factory()->create(['total' => '1060.00']); // COD, payment pending
    }

    private function pay(string $amount, PaymentTransactionStatus $status = PaymentTransactionStatus::Completed): Payment
    {
        return app(RecordPayment::class)->handle($this->order, $amount, PaymentMethod::CashOnDelivery, $status, $this->user, 'RCPT-1', 'Collected');
    }

    private function refund(string $amount): Payment
    {
        return app(RecordRefund::class)->handle($this->order, $amount, PaymentMethod::BankTransfer, $this->user, 'BANK-9', 'Damaged item');
    }

    private function assertSummary(PaymentStatus $status, string $amountPaid): void
    {
        $this->order->refresh();
        $this->assertSame($status, $this->order->payment_status);
        $this->assertSame($amountPaid, $this->order->amount_paid);
    }

    public function test_a_new_cod_order_is_pending_and_unpaid(): void
    {
        $this->assertSummary(PaymentStatus::Pending, '0.00');
        $this->assertSame(0, Payment::count());
    }

    public function test_recording_a_full_payment_marks_the_order_paid(): void
    {
        $payment = $this->pay('1060.00');

        $this->assertSame(PaymentType::Payment, $payment->type);
        $this->assertSame(PaymentTransactionStatus::Completed, $payment->status);
        $this->assertSame('1060.00', $payment->amount);
        $this->assertSame('RCPT-1', $payment->transaction_reference);
        $this->assertNotNull($payment->paid_at);
        $this->assertTrue($payment->recordedBy->is($this->user));
        $this->assertSummary(PaymentStatus::Paid, '1060.00');
    }

    public function test_a_partial_payment_marks_the_order_partially_paid(): void
    {
        $this->pay('60.00');
        $this->assertSummary(PaymentStatus::PartiallyPaid, '60.00');

        $this->pay('1000.00');
        $this->assertSummary(PaymentStatus::Paid, '1060.00');
    }

    public function test_a_failed_attempt_marks_the_order_failed_until_a_payment_succeeds(): void
    {
        $failed = $this->pay('1060.00', PaymentTransactionStatus::Failed);
        $this->assertNull($failed->paid_at);
        $this->assertSummary(PaymentStatus::Failed, '0.00');

        $this->pay('1060.00');
        $this->assertSummary(PaymentStatus::Paid, '1060.00');
    }

    public function test_partial_and_full_refunds(): void
    {
        $this->pay('1060.00');

        $refund = $this->refund('300.00');
        $this->assertSame(PaymentType::Refund, $refund->type);
        $this->assertSame('300.00', $refund->amount, 'Refund amounts are stored positive.');
        $this->assertSummary(PaymentStatus::PartiallyRefunded, '760.00');

        $this->refund('760.00');
        $this->assertSummary(PaymentStatus::Refunded, '0.00');
    }

    public function test_payments_must_be_positive(): void
    {
        foreach (['0', '-10', '0.001'] as $amount) {
            try {
                $this->pay($amount);
                $this->fail("Payment of {$amount} should be rejected.");
            } catch (PaymentException) {
            }
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_the_database_also_rejects_non_positive_amounts(): void
    {
        $this->expectException(QueryException::class);

        DB::table('payments')->insert(['order_id' => $this->order->id, 'type' => 'refund', 'method' => 'cod', 'status' => 'completed', 'amount' => '-5.00', 'currency' => 'BDT']);
    }

    public function test_payments_cannot_exceed_the_amount_due(): void
    {
        $this->pay('1000.00');

        try {
            $this->pay('60.01');
            $this->fail('Overpayment should be rejected.');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString('more than the amount due', $exception->getMessage());
        }

        $this->assertSummary(PaymentStatus::PartiallyPaid, '1000.00');
    }

    public function test_refunds_cannot_exceed_the_amount_paid(): void
    {
        $this->pay('500.00');

        $this->expectException(PaymentException::class);

        try {
            $this->refund('500.01');
        } finally {
            $this->assertSummary(PaymentStatus::PartiallyPaid, '500.00');
        }
    }

    public function test_refunds_without_any_payment_are_rejected(): void
    {
        $this->expectException(PaymentException::class);

        $this->refund('1.00');
    }

    public function test_cancelled_orders_accept_refunds_but_not_new_payments(): void
    {
        $this->pay('1060.00');
        $this->order->forceFill(['status' => OrderStatus::Cancelled])->save();

        try {
            $this->pay('1.00');
            $this->fail('New payments on a cancelled order should be rejected.');
        } catch (PaymentException) {
        }

        $this->refund('1060.00');
        $this->assertSummary(PaymentStatus::Refunded, '0.00');
    }

    public function test_the_ledger_is_authoritative_over_the_cached_summary(): void
    {
        $this->pay('600.00');
        $this->order->forceFill(['payment_status' => PaymentStatus::Paid, 'amount_paid' => '9999.00'])->save();

        app(PaymentLedger::class)->refresh($this->order);

        $this->assertSummary(PaymentStatus::PartiallyPaid, '600.00');
    }

    public function test_payment_summary_fields_are_not_mass_assignable(): void
    {
        $this->order->update(['payment_status' => 'paid', 'amount_paid' => '1060.00', 'total' => '1.00']);

        $this->assertSummary(PaymentStatus::Pending, '0.00');
        $this->assertSame('1060.00', $this->order->total);
    }
}
