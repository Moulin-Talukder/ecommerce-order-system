<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\Catalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function __construct(private Catalog $catalog) {}

    public function index(Request $request): View
    {
        $search = $this->search($request);

        return view('storefront.catalog', [
            'title' => $search !== '' ? "Search: {$search}" : null,
            'category' => null,
            'search' => $search,
            'products' => $this->catalog->listing(search: $search)->paginate(config('storefront.per_page'))->withQueryString(),
        ]);
    }

    public function category(Request $request, string $slug): View
    {
        $category = $this->catalog->findCategory($slug) ?? abort(404);
        $search = $this->search($request);

        return view('storefront.catalog', [
            'title' => $category->name,
            'category' => $category,
            'categoryPath' => $this->catalog->categoryPath($category->id),
            'search' => $search,
            'products' => $this->catalog->listing($category, $search)->paginate(config('storefront.per_page'))->withQueryString(),
        ]);
    }

    public function product(string $slug): View
    {
        $product = $this->catalog->findProduct($slug) ?? abort(404);

        return view('storefront.product', [
            'product' => $product,
            'categoryPath' => $this->catalog->categoryPath($product->category_id),
            'inStock' => $this->catalog->inStock($product),
            'maxQuantity' => $this->catalog->maxQuantity($product),
        ]);
    }

    private function search(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }
}
