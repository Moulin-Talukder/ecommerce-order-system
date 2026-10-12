<?php

use App\Services\Storefront\Cart;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Header cart badge. Reads only the session (no queries); refreshes when
 * another component dispatches "cart-updated".
 */
new class extends Component
{
    #[On('cart-updated')]
    public function refreshCount(): void
    {
        // Re-render only.
    }

    public function with(Cart $cart): array
    {
        return ['count' => $cart->count()];
    }
};
?>

<span class="inline-flex min-w-5 items-center justify-center rounded-full bg-gray-900 px-1.5 text-xs text-white tabular-nums">{{ $count }}</span>
