<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Courier integrations
    |--------------------------------------------------------------------------
    |
    | Secrets live only in .env. Never store them in the settings table, log
    | them, or send them to the browser.
    |
    | Steadfast (verified against Steadfast's official WordPress plugin,
    | v1.0.9): base URL https://portal.packzy.com/api/v1, authenticated with
    | Api-Key / Secret-Key headers. The webhook is authenticated with an
    | "Authorization: Bearer <token>" header whose token you also enter in the
    | Steadfast merchant panel.
    |
    */

    'steadfast' => [
        'base_url' => rtrim((string) env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'), '/'),
        'api_key' => env('STEADFAST_API_KEY'),
        'secret_key' => env('STEADFAST_SECRET_KEY'),
        'webhook_token' => env('STEADFAST_WEBHOOK_TOKEN'),

        // Seconds. Booking is never retried automatically (see BookShipment).
        'connect_timeout' => (int) env('STEADFAST_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('STEADFAST_TIMEOUT', 20),

        // Status lookups are read-only, so transient failures are retried.
        'lookup_retries' => (int) env('STEADFAST_LOOKUP_RETRIES', 2),
        'lookup_retry_delay_ms' => (int) env('STEADFAST_LOOKUP_RETRY_DELAY_MS', 300),

        // A booking still "in progress" after this many seconds is treated as
        // an uncertain outcome that must be reconciled before any new attempt.
        'stale_booking_seconds' => (int) env('STEADFAST_STALE_BOOKING_SECONDS', 120),
    ],

];
