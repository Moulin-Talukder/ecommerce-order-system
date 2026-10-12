<?php

use App\Exceptions\Storefront\CartException;
use App\Services\Storefront\Cart;
use App\Services\Storefront\Catalog;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Quantity selector + add to cart on the product page. The product id is
 * locked (cannot be changed from the browser) and every limit is
 * re-checked by the Cart service.
 */
new class extends Component
{
    #[Locked]
    public int $productId;

    #[Locked]
    public int $maxQuantity = 1;

    public int $quantity = 1;

    public ?string $message = null;

    public ?string $error = null;

    public function increment(): void
    {
        $this->quantity = min($this->maxQuantity, $this->quantity + 1);
    }

    public function decrement(): void
    {
        $this->quantity = max(1, $this->quantity - 1);
    }

    public function add(Cart $cart, Catalog $catalog): void
    {
        $this->reset('message', 'error');
        $product = $catalog->visibleProducts()->whereKey($this->productId)->first();

        if (! $product) {
            $this->error = 'Sorry, this product is not available.';

            return;
        }

        try {
            $inCart = $cart->add($product, max(1, min($this->quantity, (int) config('storefront.max_quantity_per_item'))));
            $this->message = "Added to cart ({$inCart} in your cart).";
            $this->dispatch('cart-updated');
        } catch (CartException $exception) {
            $this->error = $exception->getMessage();
        }
    }
};
?>

<div>
    <div class="flex items-center gap-3">
        <div class="inline-flex items-center rounded-md border border-gray-300">
            <button type="button" wire:click="decrement" class="px-3 py-2 text-lg" aria-label="Decrease quantity">−</button>
            <span class="w-10 text-center tabular-nums" aria-live="polite">{{ $quantity }}</span>
            <button type="button" wire:click="increment" class="px-3 py-2 text-lg" aria-label="Increase quantity">+</button>
        </div>
        <button type="button" wire:click="add" wire:loading.attr="disabled"
            class="flex-1 rounded-md bg-gray-900 px-4 py-3 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-60">Add to cart</button>
    </div>
    @if ($message)
        <p class="mt-3 text-sm text-green-700">{{ $message }} <a href="{{ route('storefront.cart') }}" class="font-medium underline">View cart</a></p>
    @endif
    @if ($error)
        <p class="mt-3 text-sm text-red-700">{{ $error }}</p>
    @endif
</div>
