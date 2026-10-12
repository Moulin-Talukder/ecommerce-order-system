<?php

namespace App\Services\Storefront;

use App\Services\Orders\OrderTotals;

/**
 * The cart as it stands now: purchasable lines, server-calculated totals and
 * notices about anything that was removed or reduced since it was added.
 */
final readonly class CartSummary
{
    /**
     * @param  list<CartLine>  $lines
     * @param  list<string>  $notices
     */
    public function __construct(
        public array $lines,
        public ?OrderTotals $totals,
        public array $notices,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function itemCount(): int
    {
        return array_sum(array_map(fn (CartLine $line) => $line->quantity, $this->lines));
    }

    /**
     * Whether the stored cart had to be corrected while building this summary.
     */
    public function changed(): bool
    {
        return $this->notices !== [];
    }
}
