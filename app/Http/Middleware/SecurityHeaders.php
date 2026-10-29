<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response:
 *  - X-Content-Type-Options: nosniff (no MIME sniffing of uploads/assets);
 *  - X-Frame-Options + CSP frame-ancestors 'self' (no clickjacking of the
 *    admin panel or checkout from other sites). The CSP sets only
 *    frame-ancestors, so scripts (Livewire, Vite, GTM) are not restricted;
 *  - Referrer-Policy: strict-origin-when-cross-origin, unless a route set a
 *    stricter one (search pages send "origin");
 *  - Permissions-Policy: browser features the shop never uses are off;
 *  - Strict-Transport-Security on HTTPS when SECURITY_HSTS_MAX_AGE > 0.
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }

        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $maxAge = (int) config('security.hsts_max_age');

        if ($maxAge > 0 && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }
}
