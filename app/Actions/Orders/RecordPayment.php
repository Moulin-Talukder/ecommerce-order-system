<?php

namespace App\Actions\Orders;

use App\Enums\AuditEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Exceptions\Orders\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Orders\PaymentLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Records a received (or failed) payment against an order, e.g. COD cash
 * collected or a bank transfer, and refreshes the order's payment summary.
 *
 * Audited as payment.recorded in the same transaction, with the order's
 * payment summary before and after; a failed audit write rolls it all back.
 */
class RecordPayment
{
    public function __construct(
        private PaymentLedger $ledger,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws PaymentException
     */
    public function handle(
        Order $order,
        string $amount,
        PaymentMethod $method,
        PaymentTransactionStatus $status = PaymentTransactionStatus::Completed,
        ?User $user = null,
        ?string $reference = null,
        ?string $note = null,
    ): Payment {
        $amount = bcadd($amount, '0', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new PaymentException('A payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($order, $amount, $method, $status, $user, $reference, $note): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($status === PaymentTransactionStatus::Completed) {
                if (in_array($locked->status, OrderStatus::nonRevenue(), true)) {
                    throw new PaymentException("Order {$locked->order_number} is {$locked->status->label()}; record a refund instead of a new payment.");
                }

                $due = bcsub($locked->total, $this->ledger->totals($locked)['net'], 2);

                if (bccomp($amount, $due, 2) > 0) {
                    throw new PaymentException('The payment ('.Money::format($amount).') is more than the amount due ('.Money::format($due).').');
                }
            }

            $payment = $locked->payments()->make([
                'type' => PaymentType::Payment,
                'method' => $method,
                'status' => $status,
                'amount' => $amount,
                'currency' => $locked->currency,
                'transaction_reference' => $reference,
                'paid_at' => $status === PaymentTransactionStatus::Completed ? now() : null,
                'note' => $note,
            ]);
            $payment->forceFill(['recorded_by' => $user?->id])->save();

            $summaryBefore = $locked->only(['payment_status', 'amount_paid']);
            $this->ledger->refresh($locked);

            $this->audit->record(AuditEvent::PaymentRecorded, $user, $payment, new: $this->audit->snapshot($payment), context: [
                'order_number' => $locked->order_number,
                'order_payment_status' => ['from' => $summaryBefore['payment_status'], 'to' => $locked->payment_status],
                'order_amount_paid' => ['from' => $summaryBefore['amount_paid'], 'to' => $locked->amount_paid],
            ]);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }
}
