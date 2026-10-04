<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    |
    | number_prefix: order numbers look like ORD-261004-7K3QXM. They double as
    | the Steadfast invoice and the GA4 transaction_id / Meta event_id.
    |
    | default_delivery_charge: pre-filled on the admin order form. A settings
    | screen (later phase) will replace this with configurable delivery rules.
    |
    */

    'number_prefix' => env('ORDER_NUMBER_PREFIX', 'ORD'),
    'currency' => 'BDT',
    'default_delivery_charge' => env('ORDER_DEFAULT_DELIVERY_CHARGE', '60.00'),
    'max_items' => 50,
    'max_quantity_per_item' => 1000,
    'max_delivery_charge' => '100000.00',

];
