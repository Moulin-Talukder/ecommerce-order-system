<?php

namespace App\Services\Orders;

use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Order;

/**
 * Derives an order's cached payment summary (payment_status, amount_paid)
 * from its payments ledger. The ledger is the source of truth; these two
 * columns are only ever written here.
 */
class PaymentLedger
{
    /**
     * @return array{paid: string, refunded: string, net: string, last_attempt_failed: bool}
     */
    public function totals(Order $order): array
    {
        $completed = PaymentTransactionStatus::Completed->value;

        $row = $order->payments()->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? AND status = ? THEN amount ELSE 0 END), 0) AS paid', [PaymentType::Payment->value, $completed])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? AND status = ? THEN amount ELSE 0 END), 0) AS refunded', [PaymentType::Refund->value, $completed])
            ->first();

        $lastPaymentStatus = $order->payments()
            ->toBase()
            ->where('type', PaymentType::Payment->value)
            ->latest('id')
            ->value('status');

        $paid = bcadd((string) $row->paid, '0', 2);
        $refunded = bcadd((string) $row->refunded, '0', 2);

        return [
            'paid' => $paid,
            'refunded' => $refunded,
            'net' => bcsub($paid, $refunded, 2),
            'last_attempt_failed' => $lastPaymentStatus === PaymentTransactionStatus::Failed->value,
        ];
    }

    /**
     * Recalculate and store the order's payment summary. Call inside the
     * transaction that changed the ledger, with the order row locked.
     */
    public function refresh(Order $order): Order
    {
        $totals = $this->totals($order);

        $order->forceFill([
            'amount_paid' => $totals['net'],
            'payment_status' => PaymentStatus::fromLedger($order->total, $totals['paid'], $totals['refunded'], $totals['last_attempt_failed']),
        ])->save();

        return $order;
    }
}
