<?php

namespace App\Services\Courier;

/**
 * What we know about a booking attempt after calling the courier.
 */
enum BookingOutcome: string
{
    /** The courier created the consignment and returned its identifiers. */
    case Accepted = 'accepted';

    /** The courier definitely did not create a consignment (validation/auth/rate limit, or the request never left). Safe to retry. */
    case Rejected = 'rejected';

    /** The request may have been processed (timeout, 5xx, unreadable 2xx). Must be reconciled, never blindly retried. */
    case Uncertain = 'uncertain';
}
