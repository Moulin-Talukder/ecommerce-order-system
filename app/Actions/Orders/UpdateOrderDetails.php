<?php

namespace App\Actions\Orders;

use App\Exceptions\Orders\OrderException;
use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Corrects non-financial order details. Items, prices and totals are
 * immutable after placement; delivery details can only change before the
 * order ships; the internal admin note can always change.
 */
class UpdateOrderDetails
{
    public const DELIVERY_FIELDS = [
        'customer_name', 'customer_phone', 'customer_alternate_phone', 'customer_email',
        'shipping_address', 'shipping_area', 'shipping_city', 'shipping_postal_code', 'customer_note',
    ];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws OrderException
     */
    public function handle(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $delivery = Arr::only($data, self::DELIVERY_FIELDS);

            if ($delivery !== [] && ! $locked->status->allowsDeliveryChanges()) {
                $locked->fill($delivery);

                if ($locked->isDirty()) {
                    throw new OrderException("Delivery details of order {$locked->order_number} cannot change once it is {$locked->status->label()}.");
                }
            }

            $locked->fill($delivery + Arr::only($data, ['admin_note']))->save();
            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }
}
