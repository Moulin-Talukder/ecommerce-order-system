<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Catalog product (current data only; orders keep their own snapshots).
 *
 * stock_quantity and stock_status are intentionally not fillable: stock only
 * changes through the stock service, which also writes stock_movements.
 */
#[Fillable([
    'category_id', 'brand_id', 'name', 'slug', 'sku', 'short_description', 'description',
    'purchase_price', 'selling_price', 'discount_price', 'manage_stock', 'low_stock_threshold',
    'status', 'is_featured', 'weight', 'length', 'width', 'height', 'meta_title', 'meta_description',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'manage_stock' => true,
        'stock_quantity' => 0,
        'stock_status' => 'in_stock',
        'status' => 'draft',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'manage_stock' => 'boolean',
            'stock_quantity' => 'integer',
            'stock_status' => StockStatus::class,
            'low_stock_threshold' => 'integer',
            'status' => ProductStatus::class,
            'is_featured' => 'boolean',
            'weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if ($product->manage_stock) {
                $product->stock_status = $product->stock_quantity > 0 ? StockStatus::InStock : StockStatus::OutOfStock;
            }
        });
    }

    /**
     * The price a customer pays per unit right now.
     */
    public function effectivePrice(): string
    {
        return $this->discount_price ?? $this->selling_price;
    }

    /**
     * Whether the product may be added to a new order (stock is checked separately).
     */
    public function isOrderable(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    /**
     * Whether a stock-managed product has reached its low-stock threshold.
     */
    public function isLowStock(): bool
    {
        return $this->manage_stock
            && $this->low_stock_threshold !== null
            && $this->stock_quantity <= $this->low_stock_threshold;
    }

    /**
     * SQL counterpart of {@see self::isLowStock()}.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function lowStock(Builder $query): void
    {
        $query->where('manage_stock', true)
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    /**
     * Products that are low on stock or out of stock (tracked or manually marked).
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function needsRestock(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('stock_status', StockStatus::OutOfStock)
            ->orWhere(fn (Builder $query) => $query->lowStock()));
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Images ordered for display; the first one is the primary image.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The primary image: lowest sort_order (ties broken by id).
     *
     * @return HasOne<ProductImage, $this>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
