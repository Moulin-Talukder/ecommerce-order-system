<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Exceptions\CannotDeleteException;
use App\Models\Product;
use App\Models\User;
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
 */
class ProductService
{
    public function __construct(
        private StockService $stock,
        private ImageUploader $images,
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

            if ($openingStock > 0 && $product->manage_stock) {
                $this->stock->adjust($product, $openingStock, StockMovementType::Restock, $user, 'Opening stock');
            }

            return $product;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  Validated product attributes.
     */
    public function update(Product $product, array $data): Product
    {
        unset($data['opening_stock']);

        return $this->guardUniqueness(function () use ($product, $data): Product {
            $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Product::class, $data['name'], $product->id);

            $product->update($data);

            return $product;
        });
    }

    public function setStatus(Product $product, ProductStatus $status): Product
    {
        $product->update(['status' => $status]);

        return $product;
    }

    /**
     * Delete a product that has never been ordered, together with its image files.
     *
     * @throws CannotDeleteException
     */
    public function delete(Product $product): void
    {
        if ($product->orderItems()->exists()) {
            throw new CannotDeleteException("“{$product->name}” has been ordered, so it must be kept for order history. Archive it instead.");
        }

        $files = $product->images()->get(['disk', 'path']);

        try {
            DB::transaction(function () use ($product, $files): void {
                $product->delete();

                DB::afterCommit(fn () => $files->each(fn ($image) => $this->images->delete($image->disk, $image->path)));
            });
        } catch (QueryException $exception) {
            throw new CannotDeleteException("“{$product->name}” is in use and cannot be deleted.", previous: $exception);
        }
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
