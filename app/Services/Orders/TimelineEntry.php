<?php

namespace App\Services\Orders;

use Carbon\CarbonInterface;

/**
 * One row of an order's timeline. A view model only: built from existing
 * order_status_histories and payments rows, never stored.
 */
final readonly class TimelineEntry
{
    /**
     * @param  'created'|'status'|'payment'|'payment_failed'|'refund'  $kind
     * @param  'gray'|'blue'|'green'|'red'|'yellow'  $tone
     */
    public function __construct(
        public CarbonInterface $at,
        public string $kind,
        public string $title,
        public ?string $actor,
        public ?string $detail,
        public string $tone,
    ) {}
}
