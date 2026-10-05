<?php

namespace App\Services\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Orders\OrderSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Server-side aggregates for the admin dashboard. Each method runs a small,
 * fixed number of queries regardless of data volume.
 *
 * Definitions:
 *  - Orders: orders created in the period, any status.
 *  - Sales: sum of `total` of orders created in the period, excluding
 *    cancelled and returned orders (OrderStatus::nonRevenue()).
 *  - Average order value: sales / non-cancelled, non-returned order count.
 *  - Collected: completed payments minus completed refunds whose paid_at is
 *    in the period (payments ledger), regardless of when the order was placed.
 */
class DashboardMetrics
{
    public function __construct(private OrderSearch $orders) {}

    /**
     * Order counts per status for orders created in the period (one grouped query).
     *
     * @return array{orders: int, cancelled_or_returned: int, by_status: array<string, int>}
     */
    public function orderSummary(DashboardPeriod $period): array
    {
        $rows = $this->ordersInPeriod($period)
            ->selectRaw('status, COUNT(*) AS orders')
            ->groupBy('status')
            ->pluck('orders', 'status');

        $byStatus = collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status) => [$status->value => (int) ($rows[$status->value] ?? 0)]);

        return [
            'orders' => $byStatus->sum(),
            'cancelled_or_returned' => $byStatus->only($this->nonRevenueValues())->sum(),
            'by_status' => $byStatus->all(),
        ];
    }

    /**
     * Money figures for the period (one orders query, one payments query).
     *
     * @return array{sales: string, revenue_orders: int, average_order_value: string, collected: string}
     */
    public function salesSummary(DashboardPeriod $period): array
    {
        $sales = $this->ordersInPeriod($period)
            ->whereNotIn('status', $this->nonRevenueValues())
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total), 0) AS sales')
            ->first();

        $collected = Payment::query()->toBase()
            ->where('status', PaymentTransactionStatus::Completed->value)
            ->whereBetween('paid_at', [$period->start, $period->end])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE -amount END), 0) AS net', [PaymentType::Payment->value])
            ->value('net');

        $revenueOrders = (int) $sales->orders;
        $salesTotal = bcadd((string) $sales->sales, '0', 2);

        return [
            'sales' => $salesTotal,
            'revenue_orders' => $revenueOrders,
            'average_order_value' => $revenueOrders > 0 ? bcdiv($salesTotal, (string) $revenueOrders, 2) : '0.00',
            'collected' => bcadd((string) $collected, '0', 2),
        ];
    }

    /**
     * Orders and sales per day across the period, including empty days (one grouped query).
     *
     * @return list<array{date: string, label: string, orders: int, sales: string}>
     */
    public function dailySeries(DashboardPeriod $period): array
    {
        $rows = $this->ordersInPeriod($period)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS orders')
            ->selectRaw('COALESCE(SUM(CASE WHEN status NOT IN (?, ?) THEN total ELSE 0 END), 0) AS sales', $this->nonRevenueValues())
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($day = $period->start; $day->lte($period->end); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $series[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d M'),
                'orders' => (int) ($row->orders ?? 0),
                'sales' => bcadd((string) ($row->sales ?? '0'), '0', 2),
            ];
        }

        return $series;
    }

    /**
     * Money still to be collected right now (snapshot, not period-bound).
     *
     * @return array{delivered_unpaid_orders: int, delivered_unpaid_amount: string, partially_paid_orders: int, partially_paid_amount: string}
     */
    public function outstanding(): array
    {
        $dueExpression = 'COUNT(*) AS orders, COALESCE(SUM(total - amount_paid), 0) AS due';

        $deliveredUnpaid = $this->orders->viewQuery('awaiting_payment')->toBase()->selectRaw($dueExpression)->first();

        $partiallyPaid = Order::query()->toBase()
            ->where('payment_status', PaymentStatus::PartiallyPaid->value)
            ->whereNotIn('status', $this->nonRevenueValues())
            ->selectRaw($dueExpression)
            ->first();

        return [
            'delivered_unpaid_orders' => (int) $deliveredUnpaid->orders,
            'delivered_unpaid_amount' => bcadd((string) $deliveredUnpaid->due, '0', 2),
            'partially_paid_orders' => (int) $partiallyPaid->orders,
            'partially_paid_amount' => bcadd((string) $partiallyPaid->due, '0', 2),
        ];
    }

    /**
     * Current work-queue sizes (same definitions as the order list tabs).
     *
     * @return array<string, int>
     */
    public function queueCounts(): array
    {
        return $this->orders->viewCounts();
    }

    /**
     * @return Collection<int, Order>
     */
    public function recentOrders(int $limit = 8): Collection
    {
        return Order::query()
            ->select(['id', 'order_number', 'customer_name', 'customer_phone', 'total', 'currency', 'status', 'payment_status', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Non-archived products that are low on stock or out of stock: out of
     * stock first, then lowest quantity.
     *
     * @return array{total: int, products: Collection<int, Product>}
     */
    public function productsNeedingRestock(int $limit = 8): array
    {
        $query = Product::query()->needsRestock()->where('status', '!=', ProductStatus::Archived);

        return [
            'total' => (clone $query)->count(),
            'products' => $query
                ->orderByRaw('CASE WHEN stock_status = ? THEN 0 ELSE 1 END', [StockStatus::OutOfStock->value])
                ->orderBy('stock_quantity')
                ->orderBy('name')
                ->limit($limit)
                ->get(['id', 'name', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'low_stock_threshold', 'status']),
        ];
    }

    private function ordersInPeriod(DashboardPeriod $period): QueryBuilder
    {
        return Order::query()->toBase()->whereBetween('created_at', [$period->start, $period->end]);
    }

    /**
     * @return list<string>
     */
    private function nonRevenueValues(): array
    {
        return array_map(fn (OrderStatus $status) => $status->value, OrderStatus::nonRevenue());
    }
}
