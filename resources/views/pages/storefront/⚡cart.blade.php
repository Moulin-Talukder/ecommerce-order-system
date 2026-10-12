<?php

use App\Services\Storefront\Cart;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cart page. Quantities and lines change through the Cart service; prices
 * and totals are recalculated server-side on every render.
 */
new #[Layout('layouts::storefront')] #[Title('Your cart')] class extends Component
{
    public function increment(int $productId, Cart $cart): void
    {
        $cart->update($productId, ($cart->contents()[$productId] ?? 0) + 1);
        $this->dispatch('cart-updated');
    }

    public function decrement(int $productId, Cart $cart): void
    {
        $cart->update($productId, ($cart->contents()[$productId] ?? 0) - 1);
        $this->dispatch('cart-updated');
    }

    public function remove(int $productId, Cart $cart): void
    {
        $cart->remove($productId);
        $this->dispatch('cart-updated');
    }

    public function clear(Cart $cart): void
    {
        $cart->clear();
        $this->dispatch('cart-updated');
    }

    public function with(Cart $cart): array
    {
        return ['summary' => $cart->summary()];
    }
};
?>

<div>
    <h1 class="mb-4 text-2xl font-semibold">Your cart</h1>

    @foreach ($summary->notices as $notice)
        <p role="alert" class="mb-3 rounded-md border border-yellow-200 bg-yellow-50 px-4 py-2 text-sm text-yellow-900">{{ $notice }}</p>
    @endforeach

    @if ($summary->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center">
            <p class="text-gray-600">Your cart is empty.</p>
            <a href="{{ route('storefront.home') }}" class="mt-3 inline-block rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white">Continue shopping</a>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white lg:col-span-2">
                @foreach ($summary->lines as $line)
                    <li wire:key="line-{{ $line->product->id }}" class="flex gap-3 p-4">
                        <a href="{{ route('storefront.product', $line->product->slug) }}" class="size-20 shrink-0 overflow-hidden rounded bg-gray-100">
                            @if ($line->product->primaryImage)
                                <img src="{{ $line->product->primaryImage->url() }}" alt="" class="size-full object-cover">
                            @endif
                        </a>
                        <div class="flex min-w-0 flex-1 flex-col">
                            <a href="{{ route('storefront.product', $line->product->slug) }}" class="font-medium text-gray-900 hover:underline">{{ $line->product->name }}</a>
                            <span class="text-sm text-gray-600">{{ App\Support\Money::format($line->product->effectivePrice()) }} each</span>
                            <div class="mt-2 flex flex-wrap items-center gap-3">
                                <div class="inline-flex items-center rounded-md border border-gray-300">
                                    <button type="button" wire:click="decrement({{ $line->product->id }})" class="px-3 py-1" aria-label="Decrease quantity of {{ $line->product->name }}">−</button>
                                    <span class="w-8 text-center tabular-nums">{{ $line->quantity }}</span>
                                    <button type="button" wire:click="increment({{ $line->product->id }})" class="px-3 py-1 disabled:opacity-40" @disabled($line->quantity >= $line->maxQuantity) aria-label="Increase quantity of {{ $line->product->name }}">+</button>
                                </div>
                                <button type="button" wire:click="remove({{ $line->product->id }})" class="text-sm text-red-600 hover:underline">Remove</button>
                            </div>
                        </div>
                        <div class="text-right font-medium tabular-nums">{{ App\Support\Money::format($line->pricing->lineTotal) }}</div>
                    </li>
                @endforeach
            </ul>

            <aside class="h-fit rounded-lg border border-gray-200 bg-white p-4">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt>Items ({{ $summary->itemCount() }})</dt><dd class="tabular-nums">{{ App\Support\Money::format($summary->totals->subtotal) }}</dd></div>
                    <div class="flex justify-between text-gray-500"><dt>Delivery</dt><dd>Calculated at checkout</dd></div>
                </dl>
                <a href="{{ route('storefront.checkout') }}" class="mt-4 block rounded-md bg-gray-900 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-gray-800">Proceed to checkout</a>
                <button type="button" wire:click="clear" wire:confirm="Remove all items from your cart?" class="mt-3 w-full text-sm text-gray-500 hover:underline">Clear cart</button>
            </aside>
        </div>
    @endif
</div>
