<?php

namespace App\Services\Storefront;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the public storefront may show and sell.
 *
 * A product is visible when it is active and its category (and every parent
 * category) is active, per {@see CategoryTree::isEffectivelyActive()}. A
 * brand's active flag only affects storefront filters (Phase 4 rule), not
 * product visibility. Visible products are purchasable when in stock.
 *
 * Registered as a scoped singleton so the category tree loads once per request.
 */
class Catalog
{
    private ?CategoryTree $tree = null;

    public function tree(): CategoryTree
    {
        return $this->tree ??= new CategoryTree(
            Category::query()->get(['id', 'parent_id', 'name', 'slug', 'is_active', 'sort_order'])
        );
    }

    /**
     * @return Builder<Product>
     */
    public function visibleProducts(): Builder
    {
        return Product::query()
            ->where('status', ProductStatus::Active)
            ->whereIn('category_id', $this->tree()->effectivelyActiveIds());
    }

    /**
     * Visible products with what product cards need, optionally limited to a
     * category (including its subcategories) and a search term.
     *
     * @return Builder<Product>
     */
    public function listing(?Category $category = null, string $search = ''): Builder
    {
        return $this->visibleProducts()
            ->select(['id', 'category_id', 'brand_id', 'name', 'slug', 'sku', 'selling_price', 'discount_price', 'manage_stock', 'stock_quantity', 'stock_status', 'status'])
            ->with(['primaryImage', 'brand:id,name'])
            ->when($category, fn (Builder $query, Category $category) => $query->whereIn('category_id', $this->tree()->selfAndDescendantIds($category->id)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', Like::contains($search))
                ->orWhere('sku', 'like', Like::contains($search))))
            ->orderByDesc('is_featured')
            ->orderBy('name');
    }

    public function findProduct(string $slug): ?Product
    {
        return $this->visibleProducts()
            ->where('slug', $slug)
            ->with(['images', 'brand:id,name', 'category:id,name,slug'])
            ->first();
    }

    public function findCategory(string $slug): ?Category
    {
        $category = Category::query()->where('slug', $slug)->first(['id', 'parent_id', 'name', 'slug', 'description', 'is_active', 'meta_title', 'meta_description']);

        return $category && $this->tree()->isEffectivelyActive($category->id) ? $category : null;
    }

    /**
     * Visible top-level categories for navigation, in display order.
     *
     * @return list<Category>
     */
    public function navigationCategories(): array
    {
        return collect($this->tree()->flattened())
            ->filter(fn (array $row) => $row['depth'] === 1 && $this->tree()->isEffectivelyActive($row['category']->id))
            ->pluck('category')
            ->values()
            ->all();
    }

    public function categoryPath(int $categoryId): string
    {
        return $this->tree()->path($categoryId);
    }

    public function isVisible(Product $product): bool
    {
        return $product->status === ProductStatus::Active && $this->tree()->isEffectivelyActive($product->category_id);
    }

    public function inStock(Product $product): bool
    {
        return $product->manage_stock
            ? $product->stock_quantity > 0
            : $product->stock_status === StockStatus::InStock;
    }

    public function isPurchasable(Product $product): bool
    {
        return $this->isVisible($product) && $this->inStock($product);
    }

    /**
     * The most a customer may put in the cart for this product.
     */
    public function maxQuantity(Product $product): int
    {
        $limit = (int) config('storefront.max_quantity_per_item');

        return $product->manage_stock ? min($limit, max(0, $product->stock_quantity)) : $limit;
    }
}
