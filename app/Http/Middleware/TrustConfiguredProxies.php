<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Laravel's TrustProxies, with the trusted proxies read from
 * config('security.trusted_proxies') (TRUSTED_PROXIES) on each request.
 * Unset = no proxy is trusted, so X-Forwarded-* headers are ignored.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('security.trusted_proxies'));

        if ($configured === '') {
            return null;
        }

        if ($configured === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
