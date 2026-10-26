<?php

namespace App\Services\Reports;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use App\Services\Dashboard\DashboardPeriod;

/**
 * Cash actually collected versus what orders are owed.
 *
 *  - Collections (by paid_at, from the payments ledger): completed payments,
 *    completed refunds and net collected = payments - refunds, by method.
 *    Each payment row is counted once; pending and failed rows never count.
 *  - Balances (orders placed in the period, from each order's ledger-derived
 *    amount_paid): billed = total of revenue orders; received = amount_paid
 *    (net of refunds); outstanding = total - amount_paid where positive.
 *    Unpaid = payment status pending or failed; partially paid as recorded.
 *  - Paid on cancelled/returned orders: amount_paid still held on orders
 *    excluded from sales (money to refund or review).
 *
 * Collected and billed differ on purpose: a payment counts when received,
 * an order when placed.
 */
class PaymentReport
{
    /**
     * @return array{payments: string, refunds: string, net: string, payment_count: int, refund_count: int, by_method: list<array{method: PaymentMethod, payments: string, refunds: string, net: string}>}
     */
    public function collections(DashboardPeriod $period): array
    {
        $rows = Payment::query()->toBase()
            ->where('status', PaymentTransactionStatus::Completed->value)
            ->whereBetween('paid_at', [$period->start, $period->end])
            ->selectRaw('type, method, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('type', 'method')
            ->get();

        $sum = fn (PaymentType $type, ?PaymentMethod $method = null) => $rows
            ->where('type', $type->value)
            ->when($method, fn ($rows) => $rows->where('method', $method->value))
            ->reduce(fn (string $total, object $row) => bcadd($total, ReportFilters::money($row->amount), 2), '0.00');

        $payments = $sum(PaymentType::Payment);
        $refunds = $sum(PaymentType::Refund);

        return [
            'payments' => $payments,
            'refunds' => $refunds,
            'net' => bcsub($payments, $refunds, 2),
            'payment_count' => (int) $rows->where('type', PaymentType::Payment->value)->sum('count'),
            'refund_count' => (int) $rows->where('type', PaymentType::Refund->value)->sum('count'),
            'by_method' => array_map(fn (PaymentMethod $method) => [
                'method' => $method,
                'payments' => $sum(PaymentType::Payment, $method),
                'refunds' => $sum(PaymentType::Refund, $method),
                'net' => bcsub($sum(PaymentType::Payment, $method), $sum(PaymentType::Refund, $method), 2),
            ], PaymentMethod::cases()),
        ];
    }

    /**
     * Payment attempts recorded in the period that did not (yet) complete.
     *
     * @return array{pending: int, failed: int}
     */
    public function incompleteAttempts(DashboardPeriod $period): array
    {
        $counts = Payment::query()->toBase()
            ->where('type', PaymentType::Payment->value)
            ->whereIn('status', [PaymentTransactionStatus::Pending->value, PaymentTransactionStatus::Failed->value])
            ->whereBetween('created_at', [$period->start, $period->end])
            ->selectRaw('status, COUNT(*) AS count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'pending' => (int) ($counts[PaymentTransactionStatus::Pending->value] ?? 0),
            'failed' => (int) ($counts[PaymentTransactionStatus::Failed->value] ?? 0),
        ];
    }

    /**
     * @return array{revenue_orders: int, billed: string, received: string, outstanding: string, unpaid_orders: int, unpaid_due: string, partial_orders: int, partial_due: string, paid_orders: int, refunded_orders: int, excluded_paid_orders: int, excluded_paid_amount: string}
     */
    public function balances(ReportFilters $filters): array
    {
        $in = ReportFilters::nonRevenuePlaceholders();
        $excluded = ReportFilters::nonRevenueStatuses();
        $due = 'CASE WHEN total > amount_paid THEN total - amount_paid ELSE 0 END';
        $revenue = "status NOT IN ({$in})";
        $unpaid = [PaymentStatus::Pending->value, PaymentStatus::Failed->value];
        $refunded = [PaymentStatus::Refunded->value, PaymentStatus::PartiallyRefunded->value];

        $row = $filters->orders()
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} THEN 1 ELSE 0 END), 0) AS revenue_orders", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} THEN total ELSE 0 END), 0) AS billed", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} THEN amount_paid ELSE 0 END), 0) AS received", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} THEN {$due} ELSE 0 END), 0) AS outstanding", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} AND payment_status IN (?, ?) THEN 1 ELSE 0 END), 0) AS unpaid_orders", [...$excluded, ...$unpaid])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} AND payment_status IN (?, ?) THEN {$due} ELSE 0 END), 0) AS unpaid_due", [...$excluded, ...$unpaid])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} AND payment_status = ? THEN 1 ELSE 0 END), 0) AS partial_orders", [...$excluded, PaymentStatus::PartiallyPaid->value])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} AND payment_status = ? THEN {$due} ELSE 0 END), 0) AS partial_due", [...$excluded, PaymentStatus::PartiallyPaid->value])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$revenue} AND payment_status = ? THEN 1 ELSE 0 END), 0) AS paid_orders", [...$excluded, PaymentStatus::Paid->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_status IN (?, ?) THEN 1 ELSE 0 END), 0) AS refunded_orders', $refunded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ({$in}) AND amount_paid > 0 THEN 1 ELSE 0 END), 0) AS excluded_paid_orders", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ({$in}) THEN amount_paid ELSE 0 END), 0) AS excluded_paid_amount", $excluded)
            ->first();

        return [
            'revenue_orders' => (int) $row->revenue_orders,
            'billed' => ReportFilters::money($row->billed),
            'received' => ReportFilters::money($row->received),
            'outstanding' => ReportFilters::money($row->outstanding),
            'unpaid_orders' => (int) $row->unpaid_orders,
            'unpaid_due' => ReportFilters::money($row->unpaid_due),
            'partial_orders' => (int) $row->partial_orders,
            'partial_due' => ReportFilters::money($row->partial_due),
            'paid_orders' => (int) $row->paid_orders,
            'refunded_orders' => (int) $row->refunded_orders,
            'excluded_paid_orders' => (int) $row->excluded_paid_orders,
            'excluded_paid_amount' => ReportFilters::money($row->excluded_paid_amount),
        ];
    }
}
