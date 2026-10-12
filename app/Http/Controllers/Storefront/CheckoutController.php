<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\Storefront\CheckoutCart;
use App\Exceptions\Storefront\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Services\Storefront\Cart;
use App\Services\Storefront\CheckoutSession;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function show(Cart $cart, CheckoutSession $checkout): View|RedirectResponse
    {
        $summary = $cart->summary();

        if ($summary->isEmpty()) {
            return to_route('storefront.cart')->with('error', $summary->changed()
                ? implode(' ', $summary->notices)
                : 'Your cart is empty. Add a product before checking out.');
        }

        // Server-calculated totals for each zone; the page only switches between them.
        $zoneTotals = collect(config('storefront.delivery_zones'))
            ->map(fn (array $zone) => $cart->summary($zone['charge'])->totals);

        return view('storefront.checkout', [
            'summary' => $summary,
            'zones' => config('storefront.delivery_zones'),
            'zoneTotals' => $zoneTotals,
            'checkoutToken' => $checkout->issueToken(),
        ])->with('notices', $summary->notices);
    }

    public function store(CheckoutRequest $request, CheckoutCart $checkoutCart, CheckoutSession $checkout, Cart $cart): RedirectResponse
    {
        if (filled($request->input('website'))) {
            Log::notice('Storefront checkout honeypot triggered', ['ip' => $request->ip()]);

            return to_route('storefront.checkout')->with('error', "We couldn't place this order. Please contact us for assistance.");
        }

        $token = $request->validated('checkout_token');

        if (! $checkout->tokenIssued($token)) {
            return to_route('storefront.checkout')->withInput()->with('error', 'Your checkout session expired. Please review your order and try again.');
        }

        try {
            return $checkout->lock($token)->block(15, function () use ($request, $token, $checkoutCart, $checkout, $cart): RedirectResponse {
                // A repeated submission of a token that already produced an order
                // (double-click, browser retry) shows that order instead of creating another.
                if ($existing = $checkout->orderForToken($token)) {
                    $checkout->markOwned($existing);
                    $cart->clear();

                    return to_route('storefront.thank-you', $existing);
                }

                try {
                    $order = $checkoutCart->handle($request->details());
                } catch (CheckoutException $exception) {
                    return to_route($exception->reviewCart ? 'storefront.cart' : 'storefront.checkout')
                        ->withInput()
                        ->with('error', $exception->getMessage());
                }

                $checkout->rememberOrder($token, $order->order_number);
                $cart->clear();

                return to_route('storefront.thank-you', $order->order_number);
            });
        } catch (LockTimeoutException) {
            return to_route('storefront.checkout')->with('error', 'Your order is still being processed. Please wait a moment and check your confirmation.');
        }
    }
}
