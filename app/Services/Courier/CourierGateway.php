<?php

namespace App\Services\Courier;

/**
 * Provider-neutral boundary for courier APIs. Implementations translate
 * provider HTTP details into BookingResult / LookupResult and never throw
 * for provider or network errors.
 */
interface CourierGateway
{
    /**
     * Whether credentials are configured; nothing is sent when false.
     */
    public function isConfigured(): bool;

    /**
     * Book one parcel. Must not retry: a retry could create a duplicate parcel.
     */
    public function createConsignment(ConsignmentRequest $request): BookingResult;

    /**
     * Read-only status lookup; implementations may retry transient failures.
     */
    public function lookup(LookupKey $key, string $value): LookupResult;
}
