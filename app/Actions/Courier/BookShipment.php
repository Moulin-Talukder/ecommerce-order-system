<?php

namespace App\Actions\Courier;

use App\Enums\CourierProvider;
use App\Enums\OrderStatus;
use App\Exceptions\Courier\CourierException;
use App\Models\CourierShipment;
use App\Models\Order;
use App\Models\User;
use App\Services\Courier\BookingOutcome;
use App\Services\Courier\BookingResult;
use App\Services\Courier\ConsignmentRequest;
use App\Services\Courier\CourierRegistry;
use App\Services\Courier\LookupKey;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Books an order with a courier without ever risking a duplicate parcel.
 *
 *  1. Claim (short DB transaction, order row locked): create or reuse the one
 *     courier_shipments row for (courier, invoice = order number) and mark it
 *     "booking". A second concurrent attempt sees that row and stops; the
 *     unique (courier, invoice) index is the final guard.
 *  2. Call the courier API outside any transaction, so a courier failure can
 *     never roll back or alter the order, its stock or its payments.
 *  3. Record the outcome:
 *      - accepted  -> consignment id, tracking code, raw status;
 *      - rejected  -> "booking failed" + reason; safe to try again;
 *      - uncertain -> stays "booking" and is reconciled by looking the
 *        invoice up at the courier. It is never re-sent automatically.
 */
class BookShipment
{
    public function __construct(private CourierRegistry $couriers) {}

    /**
     * @throws CourierException when the order cannot be booked or a booking already exists/is pending
     */
    public function handle(Order $order, CourierProvider $courier, ?User $user = null): CourierShipment
    {
        $gateway = $this->couriers->gateway($courier);

        if (! $gateway->isConfigured()) {
            throw new CourierException("{$courier->label()} is not configured. Add its API credentials to the server's .env file.");
        }

        $shipment = $this->claim($order, $courier, $user);
        $request = ConsignmentRequest::forOrder($order);

        $result = $gateway->createConsignment($request);

        if ($result->outcome === BookingOutcome::Uncertain) {
            return $this->reconcile($shipment, $result);
        }

        return $this->record($shipment, $result);
    }

    /**
     * Resolve a booking whose outcome is unknown by asking the courier whether
     * it has a parcel for our invoice. Never re-sends the booking.
     */
    public function reconcile(CourierShipment $shipment, ?BookingResult $previous = null): CourierShipment
    {
        $lookup = $this->couriers->gateway($shipment->courier)->lookup(LookupKey::Invoice, $shipment->invoice);

        if ($lookup->found()) {
            $shipment->forceFill([
                'status' => $lookup->status,
                'response_payload' => $lookup->response,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();

            return $shipment;
        }

        if ($lookup->notFound()) {
            return $this->record($shipment, new BookingResult(
                BookingOutcome::Rejected,
                ($previous?->message ?? 'The booking was not confirmed.').' Steadfast has no parcel for this invoice, so it is safe to book again.',
                response: $previous?->response,
                httpStatus: $previous?->httpStatus,
            ));
        }

        $shipment->forceFill([
            'last_error' => trim(($previous?->message ?? 'Booking outcome unknown.').' Could not confirm with Steadfast yet: '.$lookup->message.' Use "Check booking" before trying again.'),
            'response_payload' => $previous?->response ?? $shipment->response_payload,
        ])->save();

        return $shipment;
    }

    private function claim(Order $order, CourierProvider $courier, ?User $user): CourierShipment
    {
        try {
            return DB::transaction(function () use ($order, $courier, $user): CourierShipment {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->assertBookable($locked);

                $shipment = $locked->shipments()->where('courier', $courier)->where('invoice', $locked->order_number)->lockForUpdate()->first();

                if ($shipment?->isBooked()) {
                    throw new CourierException("Order {$locked->order_number} is already booked with {$courier->label()}.");
                }

                if ($shipment?->isBookingPending()) {
                    throw new CourierException("A booking for order {$locked->order_number} is already in progress or awaiting confirmation. Use \"Check booking\" instead of booking again.");
                }

                $shipment ??= $locked->shipments()->make(['courier' => $courier, 'invoice' => $locked->order_number]);
                $request = ConsignmentRequest::forOrder($locked);

                $shipment->forceFill([
                    'status' => CourierShipment::STATUS_BOOKING,
                    'cod_amount' => $request->codAmount,
                    'request_payload' => [
                        'invoice' => $request->invoice,
                        'recipient_name' => $request->recipientName,
                        'recipient_phone' => $request->recipientPhone,
                        'recipient_address' => $request->recipientAddress,
                        'cod_amount' => $request->codAmount,
                        'note' => $request->note,
                    ],
                    'last_error' => null,
                    'submitted_by' => $user?->id,
                    'submitted_at' => now(),
                ])->save();

                return $shipment;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CourierException("A booking for order {$order->order_number} is already in progress.");
        }
    }

    private function assertBookable(Order $order): void
    {
        if ($order->status !== OrderStatus::Processing) {
            throw new CourierException("Only orders in Processing can be booked with a courier (order {$order->order_number} is {$order->status->label()}).");
        }

        if (! PhoneNumber::isValid($order->customer_phone)) {
            throw new CourierException("Order {$order->order_number} has no valid recipient phone number.");
        }

        if (blank($order->customer_name) || blank($order->shipping_address)) {
            throw new CourierException("Order {$order->order_number} needs a recipient name and delivery address.");
        }

        if (bccomp($order->amountDue(), '0', 2) < 0) {
            throw new CourierException("Order {$order->order_number} has an invalid amount due.");
        }
    }

    private function record(CourierShipment $shipment, BookingResult $result): CourierShipment
    {
        if ($result->outcome === BookingOutcome::Accepted) {
            $shipment->forceFill([
                'consignment_id' => $result->consignmentId,
                'tracking_code' => $result->trackingCode,
                'status' => $result->status ?? 'in_review',
                'response_payload' => $result->response,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();

            return $shipment;
        }

        $shipment->forceFill([
            'status' => CourierShipment::STATUS_BOOKING_FAILED,
            'response_payload' => $result->response,
            'last_error' => $result->message,
        ])->save();

        return $shipment;
    }
}
