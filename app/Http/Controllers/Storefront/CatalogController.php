<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Storefront\Catalog;
use App\Services\Tracking\DataLayer;
use App\Services\Tracking\EcommerceEvents;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Per-request services (Catalog, DataLayer) are method-injected: controller
 * instances are cached on their route, so constructor injection would reuse
 * one request's state in the next when the application is kept in memory.
 */
class CatalogController extends Controller
{
    public function index(Request $request, Catalog $catalog, DataLayer $dataLayer, EcommerceEvents $events): View
    {
        $search = $this->search($request);
        $products = $catalog->listing(search: $search)->paginate(config('storefront.per_page'))->withQueryString();
        $this->trackList($dataLayer, $events, $search !== '' ? 'search_results' : 'all_products', $search !== '' ? 'Search results' : 'All products', $products);

        return view('storefront.catalog', [
            // The search text stays out of <title>: analytics tags collect the page title.
            'title' => $search !== '' ? 'Search results' : null,
            'category' => null,
            'search' => $search,
            'products' => $products,
        ]);
    }

    public function category(Request $request, string $slug, Catalog $catalog, DataLayer $dataLayer, EcommerceEvents $events): View
    {
        $category = $catalog->findCategory($slug) ?? abort(404);
        $search = $this->search($request);
        $products = $catalog->listing($category, $search)->paginate(config('storefront.per_page'))->withQueryString();
        $this->trackList($dataLayer, $events, 'category_'.$category->slug, $category->name, $products);

        return view('storefront.catalog', [
            'title' => $category->name,
            'category' => $category,
            'categoryPath' => $catalog->categoryPath($category->id),
            'search' => $search,
            'products' => $products,
        ]);
    }

    public function product(string $slug, Catalog $catalog, DataLayer $dataLayer, EcommerceEvents $events): View
    {
        $product = $catalog->findProduct($slug) ?? abort(404);
        $dataLayer->push($events->viewItem($product));

        return view('storefront.product', [
            'product' => $product,
            'categoryPath' => $catalog->categoryPath($product->category_id),
            'inStock' => $catalog->inStock($product),
            'maxQuantity' => $catalog->maxQuantity($product),
        ]);
    }

    /**
     * view_item_list for the products shown on this page (skipped when empty).
     *
     * @param  LengthAwarePaginator<int, Product>  $products
     */
    private function trackList(DataLayer $dataLayer, EcommerceEvents $events, string $listId, string $listName, LengthAwarePaginator $products): void
    {
        if ($products->isNotEmpty()) {
            $dataLayer->push($events->viewItemList($listId, $listName, $products->items(), $products->firstItem() - 1));
        }
    }

    private function search(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }
}
