<?php

namespace App\Services\Tracking;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Storefront\CartLine;
use App\Services\Storefront\CartSummary;
use App\Services\Storefront\Catalog;

/**
 * Builds GA4-format ecommerce dataLayer payloads (as used by Google Tag
 * Manager) from server-side data only: current products and cart lines,
 * or the saved order and its item snapshots for purchases.
 *
 * Conventions (developers.google.com/analytics/devguides/collection/ga4/ecommerce):
 *  - item price is the unit price after discount; the per-unit discount is
 *    sent separately as "discount";
 *  - "value" is the sum of price x quantity, without shipping or tax.
 *
 * Only customer-facing catalog fields are included: SKU as item_id, name,
 * brand, category path, prices, quantity. No customer data, database ids,
 * costs or stock levels.
 */
class EcommerceEvents
{
    public function __construct(private Catalog $catalog) {}

    /**
     * @return array<string, mixed>
     */
    public function viewItem(Product $product): array
    {
        $item = $this->productItem($product, 1);

        return $this->event('view_item', [
            'value' => $item['price'],
            'items' => [$item],
        ]);
    }

    /**
     * @param  iterable<Product>  $products  products in display order
     * @return array<string, mixed>
     */
    public function viewItemList(string $listId, string $listName, iterable $products, int $firstIndex = 0): array
    {
        $items = [];
        $index = $firstIndex;

        foreach ($products as $product) {
            $items[] = $this->productItem($product, null) + ['index' => $index++, 'item_list_id' => $listId, 'item_list_name' => $listName];
        }

        return $this->event('view_item_list', [
            'item_list_id' => $listId,
            'item_list_name' => $listName,
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function addToCart(Product $product, int $quantity): array
    {
        return $this->cartChange('add_to_cart', $product, $quantity);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeFromCart(Product $product, int $quantity): array
    {
        return $this->cartChange('remove_from_cart', $product, $quantity);
    }

    /**
     * @return array<string, mixed>
     */
    public function beginCheckout(CartSummary $summary): array
    {
        $items = array_map(fn (CartLine $line) => $this->productItem($line->product, $line->quantity), $summary->lines);

        return $this->event('begin_checkout', [
            'value' => $this->amount($summary->totals->subtotal),
            'items' => $items,
        ]);
    }

    /**
     * Built only from the saved order and its item snapshots.
     *
     * @return array<string, mixed>
     */
    public function purchase(Order $order): array
    {
        $items = $order->items->map(function (OrderItem $item): array {
            $unitDiscount = bcdiv($item->discount_amount, (string) $item->quantity, 2);

            return array_filter([
                'item_id' => $item->sku,
                'item_name' => $item->product_name,
                'price' => $this->amount(bcsub($item->unit_price, $unitDiscount, 2)),
                'discount' => bccomp($unitDiscount, '0', 2) > 0 ? $this->amount($unitDiscount) : null,
                'quantity' => $item->quantity,
            ], fn ($value) => $value !== null);
        })->values()->all();

        return [
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => $order->order_number,
                'currency' => $order->currency,
                // Items after product discounts, minus any order-level discount; excludes shipping (no tax is stored).
                'value' => $this->amount(bcsub($order->subtotal, $order->discount_total, 2)),
                'shipping' => $this->amount($order->delivery_charge),
                'items' => $items,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartChange(string $event, Product $product, int $quantity): array
    {
        $item = $this->productItem($product, $quantity);

        return $this->event($event, [
            'value' => $this->amount(bcmul($product->effectivePrice(), (string) $quantity, 2)),
            'items' => [$item],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productItem(Product $product, ?int $quantity): array
    {
        $unitDiscount = bcsub($product->selling_price, $product->effectivePrice(), 2);
        $item = [
            'item_id' => $product->sku,
            'item_name' => $product->name,
        ];

        if ($product->relationLoaded('brand') && $product->brand) {
            $item['item_brand'] = $product->brand->name;
        }

        $categories = array_slice(explode(' › ', $this->catalog->categoryPath($product->category_id)), 0, 5);

        foreach (array_filter($categories) as $level => $name) {
            $item[$level === 0 ? 'item_category' : 'item_category'.($level + 1)] = $name;
        }

        $item['price'] = $this->amount($product->effectivePrice());

        if (bccomp($unitDiscount, '0', 2) > 0) {
            $item['discount'] = $this->amount($unitDiscount);
        }

        if ($quantity !== null) {
            $item['quantity'] = $quantity;
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $ecommerce
     * @return array<string, mixed>
     */
    private function event(string $name, array $ecommerce): array
    {
        return ['event' => $name, 'ecommerce' => ['currency' => config('orders.currency')] + $ecommerce];
    }

    /**
     * Server-calculated decimal string -> JSON number for the dataLayer.
     */
    private function amount(string $decimal): float
    {
        return (float) bcadd($decimal, '0', 2);
    }
}
