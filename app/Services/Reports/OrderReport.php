<?php

namespace App\Services\Reports;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Support\Collection;

/**
 * Orders placed in the period, grouped by status, source and payment method
 * (one grouped query each).
 *
 *  - By status: order count and order value (sum of `total`) per status,
 *    every status listed even when zero.
 *  - By source / payment method: order count, how many of those were
 *    cancelled or returned, and net sales (total of the rest).
 */
class OrderReport
{
    /**
     * @return array{rows: list<array{status: OrderStatus, orders: int, value: string}>, orders: int, value: string}
     */
    public function byStatus(ReportFilters $filters): array
    {
        $rows = $filters->orders()
            ->selectRaw('status, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS value')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $result = array_map(fn (OrderStatus $status) => [
            'status' => $status,
            'orders' => (int) ($rows[$status->value]->orders ?? 0),
            'value' => ReportFilters::money($rows[$status->value]->value ?? '0'),
        ], OrderStatus::cases());

        return [
            'rows' => $result,
            'orders' => array_sum(array_column($result, 'orders')),
            'value' => array_reduce($result, fn (string $sum, array $row) => bcadd($sum, $row['value'], 2), '0.00'),
        ];
    }

    /**
     * @return list<array{source: OrderSource, orders: int, excluded_orders: int, net_sales: string}>
     */
    public function bySource(ReportFilters $filters): array
    {
        $rows = $this->grouped($filters, 'source');

        return array_map(fn (OrderSource $source) => ['source' => $source] + $this->groupRow($rows, $source->value), OrderSource::cases());
    }

    /**
     * @return list<array{method: PaymentMethod, orders: int, excluded_orders: int, net_sales: string}>
     */
    public function byPaymentMethod(ReportFilters $filters): array
    {
        $rows = $this->grouped($filters, 'payment_method');

        return array_map(fn (PaymentMethod $method) => ['method' => $method] + $this->groupRow($rows, $method->value), PaymentMethod::cases());
    }

    /**
     * @return Collection<string, object>
     */
    private function grouped(ReportFilters $filters, string $column): Collection
    {
        $in = ReportFilters::nonRevenuePlaceholders();
        $excluded = ReportFilters::nonRevenueStatuses();

        return $filters->orders()
            ->select($column)
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ({$in}) THEN 1 ELSE 0 END), 0) AS excluded_orders", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status NOT IN ({$in}) THEN total ELSE 0 END), 0) AS net_sales", $excluded)
            ->groupBy($column)
            ->get()
            ->keyBy($column);
    }

    /**
     * @param  Collection<string, object>  $rows
     * @return array{orders: int, excluded_orders: int, net_sales: string}
     */
    private function groupRow(Collection $rows, string $key): array
    {
        $row = $rows->get($key);

        return [
            'orders' => (int) ($row->orders ?? 0),
            'excluded_orders' => (int) ($row->excluded_orders ?? 0),
            'net_sales' => ReportFilters::money($row->net_sales ?? '0'),
        ];
    }
}
