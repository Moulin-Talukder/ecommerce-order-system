<?php

namespace App\Actions\Orders;

use App\Enums\AuditEvent;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Events\OrderStatusChanged;
use App\Exceptions\Orders\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order's status changes.
 *
 * Locks the order row, validates the transition against
 * {@see OrderStatus::transitions()}, records order_status_histories, and on
 * Cancelled/Returned puts the sold stock back exactly once:
 *
 *  - the order row lock serializes concurrent changes to the same order;
 *  - final statuses have no outgoing transitions, so a repeated cancel/return
 *    fails as an invalid transition;
 *  - independently, restocking is skipped if the order already has a
 *    cancellation/return stock movement, so stock can never be restored twice.
 *
 * Each change is audited once, in the same transaction: order.cancelled or
 * order.returned (with the quantities put back, which are not audited again
 * as stock events), otherwise order.status_changed.
 */
class ChangeOrderStatus
{
    public function __construct(
        private StockService $stock,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws InvalidOrderTransitionException
     */
    public function handle(Order $order, OrderStatus $to, ?User $user = null, ?string $note = null): Order
    {
        $from = DB::transaction(function () use ($order, $to, $user, $note): OrderStatus {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidOrderTransitionException::between($locked->order_number, $from, $to);
            }

            $locked->forceFill(['status' => $to])->save();

            $locked->statusHistories()->create([
                'old_status' => $from,
                'new_status' => $to,
                'changed_by' => $user?->id,
                'note' => $note,
            ]);

            $restocked = $to->restoresStock() ? $this->restoreStock($locked, $to, $user) : null;

            $this->audit->record(
                match ($to) {
                    OrderStatus::Cancelled => AuditEvent::OrderCancelled,
                    OrderStatus::Returned => AuditEvent::OrderReturned,
                    default => AuditEvent::OrderStatusChanged,
                },
                $user,
                $locked,
                ['status' => $from],
                ['status' => $to],
                array_filter(['note' => $note, 'restocked' => $restocked], fn ($value) => $value !== null),
            );

            $order->setRawAttributes($locked->getAttributes(), true);

            return $from;
        });

        OrderStatusChanged::dispatch($order, $from, $to);

        return $order;
    }

    /**
     * Return exactly what this order's sale movements took, once.
     *
     * @return list<array{product_id: int, quantity: int}> what was put back
     */
    private function restoreStock(Order $order, OrderStatus $to, ?User $user): array
    {
        $alreadyRestored = $order->stockMovements()
            ->whereIn('type', [StockMovementType::Cancellation, StockMovementType::Return])
            ->exists();

        if ($alreadyRestored) {
            return [];
        }

        $soldByProduct = $order->stockMovements()
            ->where('type', StockMovementType::Sale)
            ->groupBy('product_id')
            ->orderBy('product_id')
            ->selectRaw('product_id, -SUM(quantity_change) AS quantity')
            ->pluck('quantity', 'product_id');

        $type = $to === OrderStatus::Cancelled ? StockMovementType::Cancellation : StockMovementType::Return;
        $note = "Order {$order->order_number} ".strtolower($to->label());
        $restocked = [];

        foreach ($soldByProduct as $productId => $quantity) {
            $product = Product::query()->find($productId);

            // Products that stopped tracking stock since the sale have nothing to put back.
            if ($product?->manage_stock && (int) $quantity > 0) {
                $this->stock->adjust($product, (int) $quantity, $type, $user, $note, $order);
                $restocked[] = ['product_id' => (int) $productId, 'quantity' => (int) $quantity];
            }
        }

        return $restocked;
    }
}
