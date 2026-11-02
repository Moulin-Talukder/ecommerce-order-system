<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductImagesRequest;
use App\Http\Requests\Admin\UpdateProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Image routes are scope-bound: {image} must belong to {product}.
 */
class ProductImageController extends Controller
{
    public function __construct(private ProductImageService $images) {}

    public function store(StoreProductImagesRequest $request, Product $product): RedirectResponse
    {
        $added = $this->images->add($product, $request->file('images'), $request->user());

        return to_route('admin.products.show', $product)->with('success', $added->count().' '.str('image')->plural($added->count()).' uploaded.');
    }

    public function update(UpdateProductImageRequest $request, Product $product, ProductImage $image): RedirectResponse
    {
        $this->images->updateAltText($image, $request->validated('alt_text'), $request->user());

        return to_route('admin.products.show', $product)->with('success', 'Image description saved.');
    }

    public function move(Request $request, Product $product, ProductImage $image, string $direction): RedirectResponse
    {
        Gate::authorize('update', $product);

        $this->images->move($image, $direction, $request->user());

        return to_route('admin.products.show', $product)->with('success', $direction === 'first' ? 'Primary image updated.' : 'Image order updated.');
    }

    public function destroy(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        Gate::authorize('update', $product);

        $this->images->delete($image, $request->user());

        return to_route('admin.products.show', $product)->with('success', 'Image deleted.');
    }
}
