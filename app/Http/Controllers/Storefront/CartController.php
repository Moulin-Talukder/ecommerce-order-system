<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\Cart;
use App\Services\Storefront\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Plain-form "add to cart" used by product cards (no JavaScript required).
 * The product page and cart page use Livewire components that call the
 * same Cart service.
 */
class CartController extends Controller
{
    public function store(Request $request, Catalog $catalog, Cart $cart): RedirectResponse
    {
        $validated = $request->validate([
            'product' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('storefront.max_quantity_per_item')],
        ]);

        $product = $catalog->visibleProducts()->where('slug', $validated['product'])->first();

        if (! $product) {
            return back()->with('error', 'Sorry, this product is not available.');
        }

        $cart->add($product, (int) ($validated['quantity'] ?? 1));

        return back()->with('success', "“{$product->name}” was added to your cart.");
    }
}
