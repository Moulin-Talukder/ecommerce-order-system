<?php

namespace App\Actions\Orders;

use App\Enums\AuditEvent;
use App\Exceptions\Orders\OrderException;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Corrects non-financial order details. Items, prices and totals are
 * immutable after placement; delivery details can only change before the
 * order ships; the internal admin note can always change.
 *
 * A change is audited as order.updated (only the fields that changed, with
 * personal data masked) in the same transaction; an unchanged save records
 * nothing.
 */
class UpdateOrderDetails
{
    public const DELIVERY_FIELDS = [
        'customer_name', 'customer_phone', 'customer_alternate_phone', 'customer_email',
        'shipping_address', 'shipping_area', 'shipping_city', 'shipping_postal_code', 'customer_note',
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws OrderException
     */
    public function handle(Order $order, array $data, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $data, $actor): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $before = $this->audit->snapshot($locked);
            $delivery = Arr::only($data, self::DELIVERY_FIELDS);

            if ($delivery !== [] && ! $locked->status->allowsDeliveryChanges()) {
                $locked->fill($delivery);

                if ($locked->isDirty()) {
                    throw new OrderException("Delivery details of order {$locked->order_number} cannot change once it is {$locked->status->label()}.");
                }
            }

            $locked->fill($delivery + Arr::only($data, ['admin_note']))->save();
            $this->audit->recordChanges(AuditEvent::OrderUpdated, $actor, $locked, $before);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }
}
