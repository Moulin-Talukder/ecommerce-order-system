<?php

namespace App\Actions\Courier;

use App\Exceptions\Courier\CourierException;
use App\Models\CourierShipment;
use App\Services\Courier\CourierRegistry;
use App\Services\Courier\LookupKey;

/**
 * Refreshes a booked shipment's raw courier status from the courier API.
 *
 * Only the shipment row changes (status, last_synced_at, last_error,
 * response_payload). The order's own status, stock and payments are never
 * touched: staff move the order using the courier status as information.
 */
class SyncShipment
{
    public function __construct(
        private CourierRegistry $couriers,
        private BookShipment $booking,
    ) {}

    public function handle(CourierShipment $shipment): CourierShipment
    {
        $gateway = $this->couriers->gateway($shipment->courier);

        if (! $gateway->isConfigured()) {
            throw new CourierException("{$shipment->courier->label()} is not configured.");
        }

        if ($shipment->isBookingFailed()) {
            throw new CourierException('This booking failed; book the order again instead of syncing.');
        }

        if ($shipment->isBookingPending()) {
            return $this->booking->reconcile($shipment);
        }

        [$key, $value] = match (true) {
            filled($shipment->consignment_id) => [LookupKey::ConsignmentId, $shipment->consignment_id],
            filled($shipment->tracking_code) => [LookupKey::TrackingCode, $shipment->tracking_code],
            default => [LookupKey::Invoice, $shipment->invoice],
        };

        $lookup = $gateway->lookup($key, $value);

        if ($lookup->found()) {
            $shipment->forceFill([
                'status' => $lookup->status,
                'response_payload' => $lookup->response,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();
        } else {
            $shipment->forceFill(['last_error' => 'Status sync failed: '.$lookup->message])->save();
        }

        return $shipment;
    }
}
