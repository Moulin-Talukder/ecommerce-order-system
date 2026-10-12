<?php

namespace App\Services\Storefront;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Per-browser checkout state:
 *  - one-time checkout tokens issued to this session (duplicate-submit guard);
 *  - which order numbers this session placed (confirmation-page access).
 *
 * The token → order mapping and the lock live in the cache store (database
 * in this app), because two concurrent requests from the same browser each
 * read their own copy of the session and cannot see each other's writes.
 */
class CheckoutSession
{
    private const TOKENS_KEY = 'storefront.checkout_tokens';

    private const ORDERS_KEY = 'storefront.placed_orders';

    private const KEEP = 10;

    public function issueToken(): string
    {
        $token = Str::random(40);
        $tokens = array_slice([...(array) session(self::TOKENS_KEY, []), $token], -self::KEEP);
        session()->put(self::TOKENS_KEY, $tokens);

        return $token;
    }

    public function tokenIssued(string $token): bool
    {
        return in_array($token, (array) session(self::TOKENS_KEY, []), true);
    }

    public function lock(string $token): Lock
    {
        return Cache::lock($this->cacheKey($token), 30);
    }

    public function orderForToken(string $token): ?string
    {
        return Cache::get($this->cacheKey($token).':order');
    }

    /**
     * Record that this token produced this order, and that this session owns it.
     */
    public function rememberOrder(string $token, string $orderNumber): void
    {
        Cache::put($this->cacheKey($token).':order', $orderNumber, now()->addDay());
        $this->markOwned($orderNumber);
    }

    public function markOwned(string $orderNumber): void
    {
        $orders = array_values(array_unique([...(array) session(self::ORDERS_KEY, []), $orderNumber]));
        session()->put(self::ORDERS_KEY, array_slice($orders, -self::KEEP));
    }

    public function owns(string $orderNumber): bool
    {
        return in_array($orderNumber, (array) session(self::ORDERS_KEY, []), true);
    }

    /**
     * Keyed on the token alone: it is 40 random characters and is only
     * accepted if it was issued to the submitting session, while the session
     * id itself may rotate between a submit and its retry.
     */
    private function cacheKey(string $token): string
    {
        return 'storefront:checkout:'.hash('sha256', $token);
    }
}
