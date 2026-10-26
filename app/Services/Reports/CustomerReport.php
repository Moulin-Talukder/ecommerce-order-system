<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Services\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customers who bought in the period. Every order belongs to a customer
 * (orders.customer_id is required), so customers are identified by that id.
 * Only revenue orders count (cancelled and returned orders excluded):
 *
 *  - Period orders / spend: revenue orders placed in the period, sum of `total`.
 *  - Lifetime orders / spend: all revenue orders ever.
 *  - First order: the customer's earliest revenue order.
 *  - New: first order falls inside the period; returning: before it.
 *
 * The table shows the name and a masked phone only.
 */
class CustomerReport
{
    /**
     * @return array{customers: int, new: int, returning: int, orders: int, spend: string}
     */
    public function summary(DashboardPeriod $period): array
    {
        $firstOrders = $this->revenueOrders()
            ->whereIn('customer_id', $this->periodOrders($period)->select('customer_id'))
            ->select('customer_id')
            ->selectRaw('MIN(created_at) AS first_at')
            ->groupBy('customer_id');

        $customers = DB::query()->fromSub($firstOrders, 'firsts')
            ->selectRaw('COUNT(*) AS customers')
            ->selectRaw('COALESCE(SUM(CASE WHEN first_at >= ? THEN 1 ELSE 0 END), 0) AS new_customers', [$period->start])
            ->first();

        $orders = $this->periodOrders($period)
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total), 0) AS spend')
            ->first();

        return [
            'customers' => (int) $customers->customers,
            'new' => (int) $customers->new_customers,
            'returning' => (int) $customers->customers - (int) $customers->new_customers,
            'orders' => (int) $orders->orders,
            'spend' => ReportFilters::money($orders->spend),
        ];
    }

    /**
     * Customers by period spend (highest first), with lifetime figures:
     * three queries per page (page, lifetime aggregates, names).
     */
    public function customers(DashboardPeriod $period, int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = $this->periodOrders($period)
            ->select('customer_id')
            ->selectRaw('COUNT(*) AS period_orders, COALESCE(SUM(total), 0) AS period_spend')
            ->groupBy('customer_id')
            ->orderByDesc('period_spend')
            ->orderBy('customer_id')
            ->paginate($perPage, pageName: $pageName);

        $ids = $page->getCollection()->pluck('customer_id')->all();

        $lifetime = $this->revenueOrders()
            ->whereIn('customer_id', $ids)
            ->select('customer_id')
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total), 0) AS spend, MIN(created_at) AS first_at')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $customers = Customer::query()->whereKey($ids)->get(['id', 'name', 'phone'])->keyBy('id');

        return $page->setCollection($page->getCollection()->map(function (object $row) use ($lifetime, $customers, $period): array {
            $life = $lifetime->get($row->customer_id);
            $firstAt = CarbonImmutable::parse($life->first_at);

            return [
                'customer' => $customers->get($row->customer_id),
                'period_orders' => (int) $row->period_orders,
                'period_spend' => ReportFilters::money($row->period_spend),
                'lifetime_orders' => (int) $life->orders,
                'lifetime_spend' => ReportFilters::money($life->spend),
                'first_order_at' => $firstAt,
                'is_new' => $firstAt->gte($period->start),
            ];
        }));
    }

    private function revenueOrders(): Builder
    {
        return DB::table('orders')->whereNotIn('status', ReportFilters::nonRevenueStatuses());
    }

    private function periodOrders(DashboardPeriod $period): Builder
    {
        return $this->revenueOrders()->whereBetween('created_at', [$period->start, $period->end]);
    }
}
