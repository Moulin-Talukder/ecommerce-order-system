<?php

namespace App\Services\Reports;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Services\Dashboard\DashboardPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Report filters: a validated date period (Asia/Dhaka, whole days, see
 * DashboardPeriod) plus optional order status, source and payment method.
 * Order-based reports select orders by created_at (when the order was
 * placed); the payments report uses paid_at instead.
 */
final readonly class ReportFilters
{
    public function __construct(
        public DashboardPeriod $period,
        public ?OrderStatus $status = null,
        public ?OrderSource $source = null,
        public ?PaymentMethod $paymentMethod = null,
    ) {}

    /**
     * Orders placed in the period that match the filters.
     */
    public function orders(): Builder
    {
        return $this->applyTo(DB::table('orders'));
    }

    /**
     * Restrict a query that includes the orders table to the filters.
     */
    public function applyTo(Builder $query): Builder
    {
        return $query
            ->whereBetween('orders.created_at', [$this->period->start, $this->period->end])
            ->when($this->status, fn (Builder $query, OrderStatus $status) => $query->where('orders.status', $status->value))
            ->when($this->source, fn (Builder $query, OrderSource $source) => $query->where('orders.source', $source->value))
            ->when($this->paymentMethod, fn (Builder $query, PaymentMethod $method) => $query->where('orders.payment_method', $method->value));
    }

    /**
     * Statuses excluded from sales figures (cancelled, returned).
     *
     * @return list<string>
     */
    public static function nonRevenueStatuses(): array
    {
        return array_map(fn (OrderStatus $status) => $status->value, OrderStatus::nonRevenue());
    }

    /**
     * SQL "IN (?, ?)" placeholders for nonRevenueStatuses().
     */
    public static function nonRevenuePlaceholders(): string
    {
        return implode(', ', array_fill(0, count(self::nonRevenueStatuses()), '?'));
    }

    public static function money(mixed $amount): string
    {
        return bcadd((string) ($amount ?? '0'), '0', 2);
    }
}
