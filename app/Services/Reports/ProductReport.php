<?php

namespace App\Services\Reports;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product performance from order lines of revenue orders (cancelled and
 * returned orders excluded) placed in the period, and the current restock
 * list.
 *
 *  - Units sold: sum of order_items.quantity.
 *  - Sales value: sum of order_items.line_total (after product discounts,
 *    before order-level discounts, which are not split across products).
 *  - Orders: distinct orders containing the product.
 *  - Restock: the dashboard's rule (Product::needsRestock(), not archived).
 */
class ProductReport
{
    /**
     * @return array{products: int, units: int, sales_value: string}
     */
    public function summary(ReportFilters $filters): array
    {
        $row = $this->lines($filters)
            ->selectRaw('COUNT(DISTINCT order_items.product_id) AS products, COALESCE(SUM(order_items.quantity), 0) AS units, COALESCE(SUM(order_items.line_total), 0) AS sales_value')
            ->first();

        return [
            'products' => (int) $row->products,
            'units' => (int) $row->units,
            'sales_value' => ReportFilters::money($row->sales_value),
        ];
    }

    /**
     * Best sellers: most units first, then highest sales value.
     */
    public function bestSellers(ReportFilters $filters, int $perPage, string $pageName): LengthAwarePaginator
    {
        return $this->lines($filters)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->select('products.id', 'products.name', 'products.sku', 'products.manage_stock', 'products.stock_quantity', 'products.stock_status')
            ->selectRaw('SUM(order_items.quantity) AS units, SUM(order_items.line_total) AS sales_value, COUNT(DISTINCT order_items.order_id) AS orders')
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.manage_stock', 'products.stock_quantity', 'products.stock_status')
            ->orderByDesc('units')
            ->orderByDesc('sales_value')
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->paginate($perPage, pageName: $pageName);
    }

    /**
     * Non-archived products that are out of stock or at/below their low-stock
     * threshold (snapshot, not period-bound): out of stock first.
     */
    public function restock(int $perPage, string $pageName): LengthAwarePaginator
    {
        return Product::query()
            ->needsRestock()
            ->where('status', '!=', ProductStatus::Archived)
            ->orderByRaw('CASE WHEN stock_status = ? THEN 0 ELSE 1 END', [StockStatus::OutOfStock->value])
            ->orderBy('stock_quantity')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'name', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'low_stock_threshold', 'status'], $pageName);
    }

    /**
     * Order lines of revenue orders matching the filters.
     */
    private function lines(ReportFilters $filters): Builder
    {
        return $filters->applyTo(
            DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNotIn('orders.status', ReportFilters::nonRevenueStatuses())
        );
    }
}
