<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\StockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place product stock changes.
 *
 * Every change locks the product row, refuses to go below zero, updates
 * stock_quantity (stock_status follows via the Product saving hook) and
 * appends a stock_movements row in the same transaction. Product CRUD never
 * writes stock fields; the order workflow will call this service too.
 */
class StockService
{
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
     * Set availability manually for a product that does not track quantities.
     *
     * @throws StockException when the product tracks quantities (its status is derived)
     */
    public function setManualStatus(Product $product, StockStatus $status): Product
    {
        if ($product->manage_stock) {
            throw new StockException("“{$product->name}” tracks stock quantities; its availability follows the quantity.");
        }

        $product->forceFill(['stock_status' => $status])->save();

        return $product;
    }
}
