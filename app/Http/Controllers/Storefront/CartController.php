<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\Storefront\CartException;
use App\Http\Controllers\Concerns\RedirectsToSafePath;
use App\Http\Controllers\Controller;
use App\Services\Storefront\Cart;
use App\Services\Storefront\Catalog;
use App\Services\Tracking\CartChangeTracker;
use App\Services\Tracking\DataLayer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Plain-form "add to cart" used by product cards (no JavaScript required).
 * The product page and cart page use Livewire components that call the
 * same Cart service.
 *
 * Product cards post their own path as return_to: search-result pages use
 * "Referrer-Policy: origin", so back() alone would lose the search. Every
 * outcome, including a failed validation, returns there when it is safe.
 */
class CartController extends Controller
{
    use RedirectsToSafePath;

    public function store(Request $request, Catalog $catalog, Cart $cart, CartChangeTracker $tracker, DataLayer $dataLayer): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'product' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('storefront.max_quantity_per_item')],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            $returnTo = $validator->errors()->has('return_to') ? null : $request->input('return_to');

            return $this->redirectToSafePath($returnTo)->withErrors($validator)->withInput($request->except('return_to'));
        }

        $validated = $validator->validated();

        $product = $catalog->visibleProducts()->where('slug', $validated['product'])->first();

        if (! $product) {
            return $this->redirectToSafePath($validated['return_to'] ?? null)->with('error', 'Sorry, this product is not available.');
        }

        $before = $cart->contents();

        try {
            $cart->add($product, (int) ($validated['quantity'] ?? 1));
        } catch (CartException $exception) {
            return $this->redirectToSafePath($validated['return_to'] ?? null)->with('error', $exception->getMessage());
        }

        foreach ($tracker->events($before, $cart->contents()) as $event) {
            $dataLayer->flash($event);
        }

        return $this->redirectToSafePath($validated['return_to'] ?? null)->with('success', "“{$product->name}” was added to your cart.");
    }
}
