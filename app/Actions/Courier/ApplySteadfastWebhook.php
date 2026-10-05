<?php

namespace App\Actions\Courier;

use App\Enums\CourierProvider;
use App\Models\CourierShipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one (already authenticated and validated) Steadfast webhook.
 * Database-only and fast: no outbound HTTP calls.
 *
 * Replays and out-of-order deliveries are ignored by comparing the payload's
 * updated_at with the last provider update we stored for the shipment.
 */
class ApplySteadfastWebhook
{
    public const APPLIED = 'applied';

    public const IGNORED_UNKNOWN = 'unknown_consignment';

    public const IGNORED_STALE = 'stale_or_duplicate';

    /**
     * @param  array{notification_type?: string|null, consignment_id?: int|string|null, invoice?: string|null, status?: string|null, delivery_charge?: int|float|string|null, tracking_message?: string|null, updated_at?: string|null}  $payload
     * @return 'applied'|'unknown_consignment'|'stale_or_duplicate'
     */
    public function handle(array $payload): string
    {
        return DB::transaction(function () use ($payload): string {
            $shipment = $this->find($payload);

            if (! $shipment) {
                Log::notice('Steadfast webhook for an unknown consignment', [
                    'consignment_id' => $payload['consignment_id'] ?? null,
                    'invoice' => $payload['invoice'] ?? null,
                ]);

                return self::IGNORED_UNKNOWN;
            }

            $incomingAt = $this->time($payload['updated_at'] ?? null);
            $lastAt = $this->time(data_get($shipment->response_payload, 'updated_at'));

            if ($incomingAt && $lastAt && ($incomingAt->lessThan($lastAt)
                || ($incomingAt->equalTo($lastAt) && $this->sameEvent($payload, (array) $shipment->response_payload)))) {
                return self::IGNORED_STALE;
            }

            $updates = [
                'response_payload' => $payload,
                'last_synced_at' => now(),
                'last_error' => null,
            ];

            if (filled($payload['status'] ?? null)) {
                $updates['status'] = (string) $payload['status'];
            }

            if (filled($payload['consignment_id'] ?? null) && $shipment->consignment_id === null) {
                $updates['consignment_id'] = (string) $payload['consignment_id'];
            }

            if (isset($payload['delivery_charge']) && is_numeric($payload['delivery_charge'])) {
                $updates['delivery_fee'] = bcadd((string) $payload['delivery_charge'], '0', 2);
            }

            $shipment->forceFill($updates)->save();

            return self::APPLIED;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function find(array $payload): ?CourierShipment
    {
        $query = CourierShipment::query()->where('courier', CourierProvider::Steadfast)->lockForUpdate();

        if (filled($payload['consignment_id'] ?? null)) {
            $byConsignment = (clone $query)->where('consignment_id', (string) $payload['consignment_id'])->first();

            if ($byConsignment) {
                return $byConsignment;
            }
        }

        return filled($payload['invoice'] ?? null)
            ? $query->where('invoice', (string) $payload['invoice'])->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $stored
     */
    private function sameEvent(array $incoming, array $stored): bool
    {
        foreach (['notification_type', 'status', 'tracking_message'] as $key) {
            if (($incoming[$key] ?? null) !== ($stored[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
