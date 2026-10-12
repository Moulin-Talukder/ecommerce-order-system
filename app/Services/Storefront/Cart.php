<?php

namespace App\Services\Storefront;

use App\Exceptions\Storefront\CartException;
use App\Models\Product;
use App\Services\Orders\OrderCalculator;
use Illuminate\Support\Collection;

/**
 * Session cart. Stores only product id => quantity; prices, availability and
 * totals are always recalculated from the database (OrderCalculator), so the
 * browser can never influence them.
 */
class Cart
{
    private const SESSION_KEY = 'storefront.cart';

    public function __construct(
        private Catalog $catalog,
        private OrderCalculator $calculator,
    ) {}

    /**
     * @return array<int, int> product id => quantity
     */
    public function contents(): array
    {
        $raw = session(self::SESSION_KEY, []);

        return is_array($raw) ? array_filter(array_map('intval', $raw), fn (int $qty) => $qty > 0) : [];
    }

    public function count(): int
    {
        return array_sum($this->contents());
    }

    public function isEmpty(): bool
    {
        return $this->contents() === [];
    }

    /**
     * Add a quantity of a product, capped at its maximum.
     *
     * @return int The quantity of this product now in the cart.
     *
     * @throws CartException
     */
    public function add(Product $product, int $quantity = 1): int
    {
        if (! $this->catalog->isPurchasable($product)) {
            throw new CartException("Sorry, “{$product->name}” is not available right now.");
        }

        $contents = $this->contents();
        $current = $contents[$product->id] ?? 0;
        $max = $this->catalog->maxQuantity($product);

        if ($current === 0 && count($contents) >= (int) config('storefront.max_cart_lines')) {
            throw new CartException('Your cart is full. Please check out or remove an item first.');
        }

        if ($current >= $max) {
            throw new CartException("You already have the maximum quantity of “{$product->name}” in your cart.");
        }

        $contents[$product->id] = min($max, $current + max(1, $quantity));
        $this->store($contents);

        return $contents[$product->id];
    }

    /**
     * Set a product's quantity (0 removes it), capped at its maximum.
     */
    public function update(int $productId, int $quantity): void
    {
        $contents = $this->contents();

        if (! isset($contents[$productId])) {
            return;
        }

        $product = $this->catalog->visibleProducts()->whereKey($productId)->first();

        if ($quantity <= 0 || ! $product || ! $this->catalog->isPurchasable($product)) {
            unset($contents[$productId]);
        } else {
            $contents[$productId] = min($quantity, $this->catalog->maxQuantity($product));
        }

        $this->store($contents);
    }

    public function remove(int $productId): void
    {
        $contents = $this->contents();
        unset($contents[$productId]);
        $this->store($contents);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Re-check every line against the current catalog, drop what can no
     * longer be bought, reduce quantities above current stock, and price
     * the rest with OrderCalculator.
     */
    public function summary(?string $deliveryCharge = null): CartSummary
    {
        $contents = $this->contents();
        $products = $this->products(array_keys($contents));
        $notices = [];
        $lines = [];
        $normalized = [];

        foreach ($contents as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $this->catalog->isPurchasable($product)) {
                $notices[] = $product
                    ? "“{$product->name}” is out of stock and was removed from your cart."
                    : 'An item in your cart is no longer available and was removed.';

                continue;
            }

            $max = $this->catalog->maxQuantity($product);

            if ($quantity > $max) {
                $notices[] = "Only {$max} of “{$product->name}” can be ordered right now; your cart was updated.";
                $quantity = $max;
            }

            $normalized[$productId] = $quantity;
            $lines[] = ['product' => $product, 'quantity' => $quantity, 'max' => $max];
        }

        if ($normalized !== $contents) {
            $this->store($normalized);
        }

        if ($lines === []) {
            return new CartSummary([], null, $notices);
        }

        $totals = $this->calculator->calculate(
            array_map(fn (array $line) => ['product' => $line['product'], 'quantity' => $line['quantity']], $lines),
            $deliveryCharge ?? '0',
        );

        $cartLines = array_map(
            fn (array $line, int $index) => new CartLine($line['product'], $line['quantity'], $line['max'], $totals->lines[$index]),
            $lines,
            array_keys($lines),
        );

        return new CartSummary($cartLines, $totals, $notices);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function products(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $this->catalog->visibleProducts()
            ->whereKey($ids)
            ->select(['id', 'category_id', 'name', 'slug', 'sku', 'selling_price', 'discount_price', 'manage_stock', 'stock_quantity', 'stock_status', 'status'])
            ->with('primaryImage')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int, int>  $contents
     */
    private function store(array $contents): void
    {
        session()->put(self::SESSION_KEY, $contents);
    }
}
