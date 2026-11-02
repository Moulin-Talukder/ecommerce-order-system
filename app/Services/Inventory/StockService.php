<?php

namespace App\Services\Inventory;

use App\Enums\AuditEvent;
use App\Enums\StockMovementType;
use App\Enums\StockStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\StockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The only place product stock changes.
 *
 * Every change locks the product row, refuses to go below zero, updates
 * stock_quantity (stock_status follows via the Product saving hook) and
 * appends a stock_movements row in the same transaction. Product CRUD never
 * writes stock fields; the order workflow will call this service too.
 *
 * Only changes an admin makes here are audited (adjustManually(),
 * setManualStatus()). Order-driven movements and opening stock go through
 * adjust() and are covered by the order's and product's own audit entries.
 */
class StockService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Apply a signed stock change.
     *
     * @throws InsufficientStockException when the change would make stock negative
     * @throws StockException when the product does not track stock or the change is zero
     */
    public function adjust(
        Product $product,
        int $quantityChange,
        StockMovementType $type,
        ?User $user = null,
        ?string $note = null,
        ?Order $order = null,
    ): StockMovement {
        if ($quantityChange === 0) {
            throw new StockException('A stock change must not be zero.');
        }

        return DB::transaction(function () use ($product, $quantityChange, $type, $user, $note, $order): StockMovement {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if (! $locked->manage_stock) {
                throw new StockException("“{$locked->name}” does not track stock quantities.");
            }

            $newQuantity = $locked->stock_quantity + $quantityChange;

            if ($newQuantity < 0) {
                throw InsufficientStockException::for($locked, -$quantityChange);
            }

            $locked->forceFill(['stock_quantity' => $newQuantity])->save();

            $movement = $locked->stockMovements()->create([
                'order_id' => $order?->id,
                'type' => $type,
                'quantity_change' => $quantityChange,
                'quantity_after' => $newQuantity,
                'user_id' => $user?->id,
                'note' => $note,
            ]);

            $product->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        });
    }

    /**
     * A restock or adjustment entered by an admin, audited as stock.adjusted
     * with the quantity before and after (read from the locked row).
     *
     * @throws InsufficientStockException when the change would make stock negative
     * @throws StockException when the product does not track stock or the change is zero
     */
    public function adjustManually(Product $product, int $quantityChange, StockMovementType $type, User $user, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($product, $quantityChange, $type, $user, $note): StockMovement {
            $before = Arr::only($this->audit->snapshot(Product::query()->lockForUpdate()->findOrFail($product->id)), ['stock_quantity', 'stock_status']);

            $movement = $this->adjust($product, $quantityChange, $type, $user, $note);

            $this->audit->record(AuditEvent::StockAdjusted, $user, $product, $before, $product->only(['stock_quantity', 'stock_status']), [
                'type' => $type,
                'quantity_change' => $movement->quantity_change,
                'stock_movement_id' => $movement->id,
                'note' => $note,
            ]);

            return $movement;
        });
    }

    /**
     * Set availability manually for a product that does not track quantities.
     *
     * @throws StockException when the product tracks quantities (its status is derived)
     */
    public function setManualStatus(Product $product, StockStatus $status, ?User $actor = null): Product
    {
        return DB::transaction(function () use ($product, $status, $actor): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->manage_stock) {
                throw new StockException("“{$locked->name}” tracks stock quantities; its availability follows the quantity.");
            }

            $before = $this->audit->snapshot($locked);
            $locked->forceFill(['stock_status' => $status])->save();
            $this->audit->recordChanges(AuditEvent::ProductAvailabilityChanged, $actor, $locked, $before);

            $product->setRawAttributes($locked->getAttributes(), true);

            return $product;
        });
    }
}
