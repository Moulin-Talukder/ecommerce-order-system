<?php

namespace App\Services\Courier;

/**
 * Identifiers a parcel can be looked up by.
 */
enum LookupKey: string
{
    case ConsignmentId = 'consignment_id';
    case Invoice = 'invoice';
    case TrackingCode = 'tracking_code';
}
