<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Courier\BookShipment;
use App\Actions\Courier\SyncShipment;
use App\Enums\CourierProvider;
use App\Exceptions\Courier\CourierException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class OrderCourierController extends Controller
{
    public function book(Order $order, BookShipment $bookShipment): RedirectResponse
    {
        Gate::authorize('bookCourier', $order);

        $shipment = $bookShipment->handle($order, CourierProvider::Steadfast, request()->user());

        return to_route('admin.orders.show', $order)->with(...match (true) {
            $shipment->isBookingFailed() => ['error', 'Steadfast booking failed: '.$shipment->last_error],
            $shipment->isBookingPending() => ['error', $shipment->last_error ?? 'The booking could not be confirmed yet.'],
            $shipment->consignment_id === null => ['success', "Steadfast confirmed the booking for invoice {$shipment->invoice} (status: {$shipment->statusLabel()}). The consignment ID will be filled in by Steadfast's status webhook, or can be read in the Steadfast panel."],
            default => ['success', "Booked with Steadfast. Consignment {$shipment->consignment_id}".($shipment->tracking_code ? ", tracking {$shipment->tracking_code}" : '').'.'],
        });
    }

    public function sync(Order $order, SyncShipment $syncShipment): RedirectResponse
    {
        Gate::authorize('syncCourier', $order);

        $shipment = $order->shipments()->where('courier', CourierProvider::Steadfast)->latest('id')->first()
            ?? throw new CourierException("Order {$order->order_number} has not been booked with Steadfast.");

        $shipment = $syncShipment->handle($shipment);

        return to_route('admin.orders.show', $order)->with(...match (true) {
            $shipment->isBookingFailed() => ['error', 'Steadfast has no parcel for this order: '.$shipment->last_error],
            filled($shipment->last_error) => ['error', $shipment->last_error],
            default => ['success', 'Courier status: '.$shipment->statusLabel().'.'],
        });
    }
}
