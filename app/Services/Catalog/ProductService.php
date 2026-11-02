<?php

namespace App\Services\Catalog;

use App\Enums\AuditEvent;
use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Exceptions\CannotDeleteException;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\StockService;
use App\Services\Media\ImageUploader;
use App\Support\Slug;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write operations for products.
 *
 * Product data never includes stock fields (they are not fillable); opening
 * stock is recorded through {@see StockService} so it appears in the
 * stock_movements audit trail.
 *
 * Every change is audited in its transaction, so a failed audit write rolls
 * the change back. Saves that change no audited field are not recorded.
 * Opening stock is part of product.created, not a separate stock event.
 */
class ProductService
{
    public function __construct(
        private StockService $stock,
        private ImageUploader $images,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated product attributes, plus optional opening_stock.
     */
    public function create(array $data, ?User $user = null): Product
    {
        $openingStock = (int) ($data['opening_stock'] ?? 0);
        unset($data['opening_stock']);

        return $this->guardUniqueness(fn () => DB::transaction(function () use ($data, $openingStock, $user): Product {
            $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Product::class, $data['name']);

            $product = Product::create($data);

            $opened = $openingStock > 0 && $product->manage_stock;

            if ($opened) {
                $this->stock->adjust($product, $openingStock, StockMovementType::Restock, $user, 'Opening stock');
            }

            $this->audit->record(AuditEvent::ProductCreated, $user, $product, new: $this->audit->snapshot($product), context: $opened ? ['opening_stock' => $openingStock] : []);

            return $product;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  Validated product attributes.
     */
    public function update(Product $product, array $data, ?User $actor = null): Product
    {
        unset($data['opening_stock']);

        return $this->guardUniqueness(function () use ($product, $data, $actor): Product {
            $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Product::class, $data['name'], $product->id);

            return $this->change($product, $data, AuditEvent::ProductUpdated, $actor);
        });
    }

    public function setStatus(Product $product, ProductStatus $status, ?User $actor = null): Product
    {
        return $this->change($product, ['status' => $status], AuditEvent::ProductStatusChanged, $actor);
    }

    /**
     * Delete a product that has never been ordered, together with its image files.
     *
     * @throws CannotDeleteException
     */
    public function delete(Product $product, ?User $actor = null): void
    {
        if ($product->orderItems()->exists()) {
            throw new CannotDeleteException("“{$product->name}” has been ordered, so it must be kept for order history. Archive it instead.");
        }

        $files = $product->images()->get(['disk', 'path']);

        try {
            DB::transaction(function () use ($product, $files, $actor): void {
                $product->delete();
                $this->audit->record(AuditEvent::ProductDeleted, $actor, $product, old: $this->audit->snapshot($product), context: ['images_deleted' => $files->count()]);

                DB::afterCommit(fn () => $files->each(fn ($image) => $this->images->delete($image->disk, $image->path)));
            });
        } catch (QueryException $exception) {
            if (! CannotDeleteException::isReferencedRowError($exception)) {
                throw $exception;
            }

            throw new CannotDeleteException("“{$product->name}” is in use and cannot be deleted.", previous: $exception);
        }
    }

    /**
     * Update the locked row and audit what actually changed.
     *
     * @param  array<string, mixed>  $data
     */
    private function change(Product $product, array $data, AuditEvent $event, ?User $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $event, $actor): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = $this->audit->snapshot($locked);

            $locked->update($data);
            $this->audit->recordChanges($event, $actor, $locked, $before);

            $product->setRawAttributes($locked->getAttributes(), true);

            return $product;
        });
    }

    /**
     * Turn a lost race on the unique sku/slug indexes into a normal validation error.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guardUniqueness(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $exception) {
            $field = match (true) {
                str_contains($exception->getMessage(), 'products_sku_unique') => 'sku',
                str_contains($exception->getMessage(), 'products_slug_unique') => 'slug',
                default => throw $exception,
            };

            throw ValidationException::withMessages([
                $field => "This {$field} is already used by another product.",
            ]);
        }
    }
}
