<?php

namespace App\Services\Reports;

/**
 * Sales figures for orders placed in the period (two summary queries and
 * one grouped daily query, regardless of data volume).
 *
 * Definitions (same as the dashboard):
 *  - Orders placed: every order created in the period, any status.
 *  - Gross order value: sum of `total` of all those orders.
 *  - Excluded: cancelled and returned orders (OrderStatus::nonRevenue()).
 *  - Net sales: sum of `total` of the remaining (revenue) orders
 *      = product sales - order discounts + delivery charges.
 *  - Product sales: sum of `subtotal` (line totals, after product discounts).
 *  - Product discounts: sum of order_items.discount_amount on revenue orders.
 *  - Average order value: net sales / revenue orders.
 */
class SalesReport
{
    /**
     * @return array{orders_placed: int, gross_value: string, excluded_orders: int, excluded_value: string, revenue_orders: int, gross_product_value: string, product_discounts: string, product_sales: string, order_discounts: string, delivery_charges: string, net_sales: string, average_order_value: string}
     */
    public function summary(ReportFilters $filters): array
    {
        $in = ReportFilters::nonRevenuePlaceholders();
        $excluded = ReportFilters::nonRevenueStatuses();

        $row = $filters->orders()
            ->selectRaw('COUNT(*) AS orders_placed, COALESCE(SUM(total), 0) AS gross_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ({$in}) THEN 1 ELSE 0 END), 0) AS excluded_orders", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ({$in}) THEN total ELSE 0 END), 0) AS excluded_value", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status NOT IN ({$in}) THEN subtotal ELSE 0 END), 0) AS product_sales", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status NOT IN ({$in}) THEN discount_total ELSE 0 END), 0) AS order_discounts", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status NOT IN ({$in}) THEN delivery_charge ELSE 0 END), 0) AS delivery_charges", $excluded)
            ->selectRaw("COALESCE(SUM(CASE WHEN status NOT IN ({$in}) THEN total ELSE 0 END), 0) AS net_sales", $excluded)
            ->first();

        // Separate query: joining order_items into the query above would repeat each order once per line.
        $productDiscounts = $filters->orders()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereNotIn('orders.status', $excluded)
            ->selectRaw('COALESCE(SUM(order_items.discount_amount), 0) AS discounts')
            ->value('discounts');

        $revenueOrders = (int) $row->orders_placed - (int) $row->excluded_orders;
        $netSales = ReportFilters::money($row->net_sales);
        $productSales = ReportFilters::money($row->product_sales);
        $productDiscounts = ReportFilters::money($productDiscounts);

        return [
            'orders_placed' => (int) $row->orders_placed,
            'gross_value' => ReportFilters::money($row->gross_value),
            'excluded_orders' => (int) $row->excluded_orders,
            'excluded_value' => ReportFilters::money($row->excluded_value),
            'revenue_orders' => $revenueOrders,
            'gross_product_value' => bcadd($productSales, $productDiscounts, 2),
            'product_discounts' => $productDiscounts,
            'product_sales' => $productSales,
            'order_discounts' => ReportFilters::money($row->order_discounts),
            'delivery_charges' => ReportFilters::money($row->delivery_charges),
            'net_sales' => $netSales,
            'average_order_value' => $revenueOrders > 0 ? bcdiv($netSales, (string) $revenueOrders, 2) : '0.00',
        ];
    }

    /**
     * Revenue orders and net sales per day, including empty days.
     *
     * @return list<array{date: string, label: string, orders: int, net_sales: string}>
     */
    public function daily(ReportFilters $filters): array
    {
        $rows = $filters->orders()
            ->whereNotIn('status', ReportFilters::nonRevenueStatuses())
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS net_sales')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = [];

        for ($day = $filters->period->start; $day->lte($filters->period->end); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $days[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('D, d M'),
                'orders' => (int) ($row->orders ?? 0),
                'net_sales' => ReportFilters::money($row->net_sales ?? '0'),
            ];
        }

        return $days;
    }
}
