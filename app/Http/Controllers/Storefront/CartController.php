<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\Cart;
use App\Services\Storefront\Catalog;
use App\Services\Tracking\CartChangeTracker;
use App\Services\Tracking\DataLayer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Plain-form "add to cart" used by product cards (no JavaScript required).
 * The product page and cart page use Livewire components that call the
 * same Cart service.
 *
 * Product cards post their own path as return_to: search-result pages use
 * "Referrer-Policy: origin", so back() alone would lose the search.
 */
class CartController extends Controller
{
    public function store(Request $request, Catalog $catalog, Cart $cart, CartChangeTracker $tracker, DataLayer $dataLayer): RedirectResponse
    {
        $validated = $request->validate([
            'product' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('storefront.max_quantity_per_item')],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $product = $catalog->visibleProducts()->where('slug', $validated['product'])->first();

        if (! $product) {
            return $this->returnTo($validated['return_to'] ?? null)->with('error', 'Sorry, this product is not available.');
        }

        $before = $cart->contents();
        $cart->add($product, (int) ($validated['quantity'] ?? 1));

        foreach ($tracker->events($before, $cart->contents()) as $event) {
            $dataLayer->flash($event);
        }

        return $this->returnTo($validated['return_to'] ?? null)->with('success', "“{$product->name}” was added to your cart.");
    }

    /**
     * Only a same-site relative path is accepted (no open redirects).
     */
    private function returnTo(?string $path): RedirectResponse
    {
        if (is_string($path) && preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) === 1) {
            return redirect()->to(url($path));
        }

        return back();
    }
}
