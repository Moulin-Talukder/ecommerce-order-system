<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Builds a chronological timeline from an order's status history and payment
 * ledger. Nothing is stored: the underlying rows remain the only source.
 *
 * Expects `statusHistories.changedBy` and `payments.recordedBy` to be eager
 * loaded (loaded here if they are not).
 */
class OrderTimeline
{
    /**
     * @return Collection<int, TimelineEntry> oldest first
     */
    public function for(Order $order): Collection
    {
        $order->loadMissing(['statusHistories.changedBy:id,name', 'payments.recordedBy:id,name']);

        $statusEntries = $order->statusHistories->map(fn (OrderStatusHistory $history) => [
            'sort' => [$history->created_at->getTimestamp(), 0, $history->id],
            'entry' => $this->statusEntry($order, $history),
        ]);

        $paymentEntries = $order->payments->map(fn (Payment $payment) => [
            'sort' => [$payment->created_at->getTimestamp(), 1, $payment->id],
            'entry' => $this->paymentEntry($order, $payment),
        ]);

        return $statusEntries->concat($paymentEntries)
            ->sortBy(fn (array $row) => $row['sort'])
            ->pluck('entry')
            ->values();
    }

    private function statusEntry(Order $order, OrderStatusHistory $history): TimelineEntry
    {
        if ($history->old_status === null) {
            return new TimelineEntry(
                at: $history->created_at,
                kind: 'created',
                title: 'Order placed ('.$order->source->label().')',
                actor: $history->changedBy?->name,
                detail: $history->note === 'Order placed' ? null : $history->note,
                tone: 'blue',
            );
        }

        return new TimelineEntry(
            at: $history->created_at,
            kind: 'status',
            title: "{$history->old_status->label()} → {$history->new_status->label()}",
            actor: $history->changedBy?->name,
            detail: $history->note,
            tone: match ($history->new_status) {
                OrderStatus::Delivered => 'green',
                OrderStatus::Cancelled, OrderStatus::Returned => 'red',
                default => 'gray',
            },
        );
    }

    private function paymentEntry(Order $order, Payment $payment): TimelineEntry
    {
        $amount = Money::format($payment->amount, $order->currency);
        $detail = collect([
            $payment->transaction_reference ? "Ref {$payment->transaction_reference}" : null,
            $payment->note,
        ])->filter()->implode(' · ') ?: null;

        [$kind, $title, $tone] = match (true) {
            $payment->type === PaymentType::Refund => ['refund', "Refund of {$amount} ({$payment->method->label()})", 'red'],
            $payment->status === PaymentTransactionStatus::Failed => ['payment_failed', "Failed payment attempt of {$amount} ({$payment->method->label()})", 'yellow'],
            default => ['payment', "Payment of {$amount} received ({$payment->method->label()})", 'green'],
        };

        return new TimelineEntry($payment->created_at, $kind, $title, $payment->recordedBy?->name, $detail, $tone);
    }
}
