<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer storefront
    |--------------------------------------------------------------------------
    |
    | Delivery charges are decided by the server from the zone the customer
    | picks; the browser never submits an amount. A settings screen (later
    | phase) may replace these values.
    |
    */

    'delivery_zones' => [
        'inside_dhaka' => ['label' => 'Inside Dhaka', 'charge' => '60.00'],
        'outside_dhaka' => ['label' => 'Outside Dhaka', 'charge' => '120.00'],
    ],

    'per_page' => 12,

    // Cart limits, enforced server-side.
    'max_quantity_per_item' => 10,
    'max_cart_lines' => 20,

    // Checkout submissions per minute per IP, and per hour per phone number.
    'checkout_attempts_per_minute_per_ip' => 10,
    'checkout_attempts_per_hour_per_phone' => 10,

];
