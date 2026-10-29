<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Behind a load balancer or reverse proxy that terminates HTTPS, Laravel
    | must trust its X-Forwarded-* headers to know the request was secure
    | (secure cookies, https URLs). Empty = trust no proxy (default, safe for
    | direct serving). "*" trusts the immediate proxy, for platforms where
    | the app is only reachable through it; otherwise list the proxy IPs or
    | CIDR ranges, comma-separated.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Seconds browsers must use HTTPS only (sent on HTTPS responses). 0 = off.
    | Enable in production only after HTTPS works for the whole domain, e.g.
    | 31536000 (one year); it cannot easily be undone once browsers cache it.
    |
    */

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 0),

];
