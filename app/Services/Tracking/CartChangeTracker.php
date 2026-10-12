<?php

namespace App\Services\Tracking;

use App\Services\Storefront\Catalog;

/**
 * Turns a confirmed cart change (contents before vs after a successful Cart
 * operation) into add_to_cart / remove_from_cart events for exactly the
 * quantities that changed. Rejected operations change nothing and produce
 * no events.
 */
class CartChangeTracker
{
    public function __construct(
        private TagManager $tagManager,
        private Catalog $catalog,
        private EcommerceEvents $events,
    ) {}

    /**
     * @param  array<int, int>  $before  product id => quantity
     * @param  array<int, int>  $after  product id => quantity
     * @return list<array<string, mixed>>
     */
    public function events(array $before, array $after): array
    {
        if (! $this->tagManager->enabled()) {
            return [];
        }

        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $productId) {
            $delta = ($after[$productId] ?? 0) - ($before[$productId] ?? 0);

            if ($delta !== 0) {
                $changes[$productId] = $delta;
            }
        }

        if ($changes === []) {
            return [];
        }

        $products = $this->catalog->visibleProducts()->whereKey(array_keys($changes))->with('brand:id,name')->get()->keyBy('id');
        $events = [];

        foreach ($changes as $productId => $delta) {
            if ($product = $products->get($productId)) {
                $events[] = $delta > 0
                    ? $this->events->addToCart($product, $delta)
                    : $this->events->removeFromCart($product, -$delta);
            }
        }

        return $events;
    }
}
