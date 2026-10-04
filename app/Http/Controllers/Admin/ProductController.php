<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Requests\Admin\UpdateProductStatusRequest;
use App\Models\Product;
use App\Services\Catalog\BrandService;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\ProductService;
use App\Support\Like;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $products,
        private BrandService $brands,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $tree = CategoryTree::load();
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => $request->integer('category') ?: null,
            'brand' => $request->integer('brand') ?: null,
            'status' => ProductStatus::tryFrom((string) $request->query('status')),
            'stock' => in_array($request->query('stock'), ['in_stock', 'out_of_stock', 'low'], true) ? $request->query('stock') : null,
        ];

        $products = Product::query()
            ->with(['category:id,name', 'brand:id,name', 'primaryImage'])
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', Like::contains($filters['search']))
                ->orWhere('sku', 'like', Like::contains($filters['search']))
                ->orWhere('slug', 'like', Like::contains($filters['search']))))
            ->when($filters['category'], fn ($query, int $id) => $query->whereIn('category_id', $tree->selfAndDescendantIds($id)))
            ->when($filters['brand'], fn ($query, int $id) => $query->where('brand_id', $id))
            ->when($filters['status'], fn ($query, ProductStatus $status) => $query->where('status', $status))
            ->when($filters['stock'] === 'low', fn ($query) => $query
                ->where('manage_stock', true)
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold'))
            ->when(in_array($filters['stock'], ['in_stock', 'out_of_stock'], true), fn ($query) => $query->where('stock_status', StockStatus::from($filters['stock'])))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'filters' => $filters,
            'isFiltered' => array_filter($filters) !== [],
            'categoryOptions' => $tree->allOptions(),
            'brandOptions' => $this->brands->productBrandOptions(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('admin.products.create', [
            'product' => new Product(['status' => ProductStatus::Draft, 'manage_stock' => true]),
            'categoryOptions' => CategoryTree::load()->productCategoryOptions(),
            'brandOptions' => $this->brands->productBrandOptions(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->create($request->validated(), $request->user());

        return to_route('admin.products.show', $product)->with('success', "Product “{$product->name}” created. You can now add images.");
    }

    public function show(Product $product): View
    {
        Gate::authorize('view', $product);

        $product->load(['category', 'brand', 'images'])->loadCount('orderItems');

        return view('admin.products.show', [
            'product' => $product,
            'categoryPath' => CategoryTree::load()->path($product->category_id),
            'movements' => $product->stockMovements()->with('user:id,name')->latest('id')->limit(15)->get(),
        ]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('admin.products.edit', [
            'product' => $product,
            'categoryOptions' => CategoryTree::load()->productCategoryOptions($product->category_id),
            'brandOptions' => $this->brands->productBrandOptions($product->brand_id),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->validated());

        return to_route('admin.products.show', $product)->with('success', "Product “{$product->name}” updated.");
    }

    public function updateStatus(UpdateProductStatusRequest $request, Product $product): RedirectResponse
    {
        $status = $request->enum('status', ProductStatus::class);
        $this->products->setStatus($product, $status);

        return back()->with('success', "Product “{$product->name}” is now {$status->label()}.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $this->products->delete($product);

        return to_route('admin.products.index')->with('success', "Product “{$product->name}” deleted.");
    }
}
