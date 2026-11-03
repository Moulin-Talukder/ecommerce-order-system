<?php

namespace App\Actions\Orders;

use App\Enums\AuditEvent;
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
 * Records money returned to the customer. A refund is a separate positive
 * ledger row of type "refund"; it can never exceed what has been paid.
 *
 * Audited as payment.refunded in the same transaction (the reason is stored
 * only as "[changed]"); a failed audit write rolls it all back.
 */
class RecordRefund
{
    public function __construct(
        private PaymentLedger $ledger,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws PaymentException
     */
    public function handle(Order $order, string $amount, PaymentMethod $method, ?User $user = null, ?string $reference = null, ?string $note = null): Payment
    {
        $amount = bcadd($amount, '0', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new PaymentException('A refund amount must be greater than zero.');
        }

        return DB::transaction(function () use ($order, $amount, $method, $user, $reference, $note): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $refundable = $this->ledger->totals($locked)['net'];

            if (bccomp($amount, $refundable, 2) > 0) {
                throw new PaymentException('The refund ('.Money::format($amount).') is more than the amount paid ('.Money::format($refundable).').');
            }

            $refund = $locked->payments()->make([
                'type' => PaymentType::Refund,
                'method' => $method,
                'status' => PaymentTransactionStatus::Completed,
                'amount' => $amount,
                'currency' => $locked->currency,
                'transaction_reference' => $reference,
                'paid_at' => now(),
                'note' => $note,
            ]);
            $refund->forceFill(['recorded_by' => $user?->id])->save();

            $summaryBefore = $locked->only(['payment_status', 'amount_paid']);
            $this->ledger->refresh($locked);

            $this->audit->record(AuditEvent::PaymentRefunded, $user, $refund, new: $this->audit->snapshot($refund), context: [
                'order_number' => $locked->order_number,
                'order_payment_status' => ['from' => $summaryBefore['payment_status'], 'to' => $locked->payment_status],
                'order_amount_paid' => ['from' => $summaryBefore['amount_paid'], 'to' => $locked->amount_paid],
            ]);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $refund;
        });
    }
}
