<?php

namespace App\Services\Courier;

/**
 * Normalized result of a booking attempt. $message is safe to show admins;
 * $response is the provider body (never contains our credentials).
 *
 * @phpstan-type ProviderBody array<string, mixed>
 */
final readonly class BookingResult
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public BookingOutcome $outcome,
        public string $message,
        public ?string $consignmentId = null,
        public ?string $trackingCode = null,
        public ?string $status = null,
        public ?array $response = null,
        public ?int $httpStatus = null,
    ) {}
}
