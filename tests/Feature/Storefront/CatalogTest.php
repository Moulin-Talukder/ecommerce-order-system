<?php

namespace Tests\Feature\Storefront;

use App\Enums\StockStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->category = Category::factory()->create(['name' => 'Panjabi', 'slug' => 'panjabi']);
    }

    private function product(array $attributes = [], ?Category $category = null): Product
    {
        return Product::factory()->for($category ?? $this->category)->withStock(10)->create($attributes);
    }

    // --- Home / catalog ---------------------------------------------------------------------

    public function test_the_homepage_lists_visible_products_with_prices_and_brand(): void
    {
        $brand = Brand::factory()->create(['name' => 'Aarong']);
        $this->product(['name' => 'Cotton Panjabi', 'selling_price' => '1500.00', 'brand_id' => $brand->id]);
        $this->product(['name' => 'Silk Panjabi', 'selling_price' => '3000.00', 'discount_price' => '2500.00']);

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Cotton Panjabi')
            ->assertSee('Aarong')
            ->assertSee('৳1,500.00')
            ->assertSeeInOrder(['Silk Panjabi', '৳2,500.00', '৳3,000.00'])
            ->assertSee('Add to cart');
    }

    public function test_inactive_archived_and_hidden_category_products_are_excluded(): void
    {
        $this->product(['name' => 'Visible Item']);
        $this->product(['name' => 'Draft Item'])->forceFill(['status' => 'draft'])->save();
        Product::factory()->for($this->category)->archived()->create(['name' => 'Archived Item']);
        $hiddenParent = Category::factory()->inactive()->create();
        $child = Category::factory()->childOf($hiddenParent)->create();
        $this->product(['name' => 'Hidden Parent Item'], $child);
        $this->product(['name' => 'Inactive Category Item'], Category::factory()->inactive()->create());

        $this->get(route('storefront.home'))
            ->assertSee('Visible Item')
            ->assertDontSee('Draft Item')
            ->assertDontSee('Archived Item')
            ->assertDontSee('Hidden Parent Item')
            ->assertDontSee('Inactive Category Item');
    }

    public function test_inactive_brands_do_not_hide_products(): void
    {
        $brand = Brand::factory()->create(['name' => 'Old Brand', 'is_active' => false]);
        $this->product(['name' => 'Still Sold', 'brand_id' => $brand->id]);

        $this->get(route('storefront.home'))->assertSee('Still Sold');
    }

    public function test_out_of_stock_products_are_visible_but_not_addable(): void
    {
        Product::factory()->for($this->category)->outOfStock()->create(['name' => 'Sold Out Saree']);

        $this->get(route('storefront.home'))
            ->assertSee('Sold Out Saree')
            ->assertSee('Out of stock')
            ->assertDontSee('Add to cart');
    }

    public function test_internal_fields_are_never_shown(): void
    {
        $this->product(['name' => 'Kurta', 'purchase_price' => '777.77', 'stock_quantity' => 4321, 'meta_description' => 'x']);

        $this->get(route('storefront.home'))
            ->assertDontSee('777.77')
            ->assertDontSee('4321')
            ->assertDontSee('/admin');
    }

    public function test_search_matches_name_and_sku_and_treats_wildcards_literally(): void
    {
        $this->product(['name' => 'Blue Denim Jacket', 'sku' => 'JKT-1']);
        $this->product(['name' => 'Red Saree', 'sku' => 'SAREE-77']);

        $this->get(route('storefront.home', ['q' => 'denim']))->assertSee('Blue Denim Jacket')->assertDontSee('Red Saree');
        $this->get(route('storefront.home', ['q' => 'saree-77']))->assertSee('Red Saree')->assertDontSee('Blue Denim Jacket');
        $this->get(route('storefront.home', ['q' => '%']))->assertSee('No products match your search.');
    }

    public function test_the_catalog_is_paginated(): void
    {
        foreach (range(1, 15) as $i) {
            $this->product(['name' => sprintf('Product %02d', $i)]);
        }

        $this->get(route('storefront.home'))->assertSee('Product 12')->assertDontSee('Product 13');
        $this->get(route('storefront.home', ['page' => 2]))->assertSee('Product 13')->assertSee('Product 15')->assertDontSee('Product 01');
    }

    public function test_products_without_images_show_a_placeholder_and_images_use_the_primary(): void
    {
        $this->product(['name' => 'No Photo']);
        $withImage = $this->product(['name' => 'With Photo']);
        ProductImage::factory()->for($withImage)->create(['path' => 'products/second.jpg', 'sort_order' => 1]);
        ProductImage::factory()->for($withImage)->create(['path' => 'products/primary.jpg', 'sort_order' => 0]);

        $this->get(route('storefront.home'))
            ->assertSee('No image')
            ->assertSee('products/primary.jpg')
            ->assertDontSee('products/second.jpg');
    }

    public function test_navigation_lists_only_visible_top_level_categories(): void
    {
        Category::factory()->inactive()->create(['name' => 'Retired Section']);
        Category::factory()->childOf($this->category)->create(['name' => 'Kids Panjabi']);

        $this->get(route('storefront.home'))
            ->assertSee(route('storefront.category', 'panjabi'))
            ->assertDontSee('Retired Section')
            ->assertDontSee(route('storefront.category', 'kids-panjabi'));
    }

    public function test_the_catalog_runs_a_constant_number_of_queries(): void
    {
        $brand = Brand::factory()->create();
        $this->product(['brand_id' => $brand->id]);
        $few = $this->countQueries(fn () => $this->get(route('storefront.home'))->assertOk());

        foreach (range(1, 11) as $i) {
            $product = $this->product(['brand_id' => Brand::factory()->create()->id]);
            ProductImage::factory()->for($product)->create();
        }
        $many = $this->countQueries(fn () => $this->get(route('storefront.home'))->assertOk());

        $this->assertSame($few, $many);
    }

    // --- Categories --------------------------------------------------------------------------

    public function test_a_category_page_lists_its_products_including_subcategories(): void
    {
        $kids = Category::factory()->childOf($this->category)->create(['name' => 'Kids', 'slug' => 'kids']);
        $this->product(['name' => 'Adult Panjabi']);
        $this->product(['name' => 'Kids Panjabi'], $kids);
        $this->product(['name' => 'Jamdani Saree'], Category::factory()->create(['name' => 'Saree']));

        $this->get(route('storefront.category', 'panjabi'))
            ->assertOk()
            ->assertSee('Adult Panjabi')
            ->assertSee('Kids Panjabi')
            ->assertDontSee('Jamdani Saree');

        $this->get(route('storefront.category', 'kids'))
            ->assertSee('Kids Panjabi')
            ->assertDontSee('Adult Panjabi')
            ->assertSee('Panjabi › Kids');
    }

    public function test_category_search(): void
    {
        $this->product(['name' => 'Cotton Panjabi']);
        $this->product(['name' => 'Silk Panjabi']);

        $this->get(route('storefront.category', ['slug' => 'panjabi', 'q' => 'silk']))->assertSee('Silk Panjabi')->assertDontSee('Cotton Panjabi');
    }

    public function test_inactive_or_unknown_categories_return_404(): void
    {
        Category::factory()->inactive()->create(['slug' => 'retired']);
        $parent = Category::factory()->inactive()->create();
        Category::factory()->childOf($parent)->create(['slug' => 'orphaned-child']);

        $this->get(route('storefront.category', 'retired'))->assertNotFound();
        $this->get(route('storefront.category', 'orphaned-child'))->assertNotFound();
        $this->get(route('storefront.category', 'does-not-exist'))->assertNotFound();
    }

    // --- Product page ---------------------------------------------------------------------------

    public function test_the_product_page_shows_details_and_pricing(): void
    {
        $brand = Brand::factory()->create(['name' => 'Aarong']);
        $product = $this->product([
            'name' => 'Silk Panjabi', 'slug' => 'silk-panjabi', 'sku' => 'SILK-1', 'brand_id' => $brand->id,
            'selling_price' => '3000.00', 'discount_price' => '2500.00', 'description' => "Hand-woven silk.\nDry clean only.",
            'purchase_price' => '1234.56',
        ]);

        $this->get(route('storefront.product', 'silk-panjabi'))
            ->assertOk()
            ->assertSee('Silk Panjabi')
            ->assertSee('Aarong')
            ->assertSee('SKU SILK-1')
            ->assertSee('Panjabi')
            ->assertSeeInOrder(['৳2,500.00', '৳3,000.00'])
            ->assertSee('You save ৳500.00')
            ->assertSee('In stock')
            ->assertSee('Hand-woven silk.')
            ->assertDontSee('1234.56')
            ->assertSeeLivewire('storefront.add-to-cart');
        $this->assertTrue($product->exists);
    }

    public function test_out_of_stock_product_pages_are_viewable_without_add_to_cart(): void
    {
        Product::factory()->for($this->category)->outOfStock()->create(['slug' => 'gone']);
        $manual = Product::factory()->for($this->category)->create(['slug' => 'manual-out', 'manage_stock' => false]);
        $manual->forceFill(['stock_status' => StockStatus::OutOfStock])->save();

        foreach (['gone', 'manual-out'] as $slug) {
            $this->get(route('storefront.product', $slug))
                ->assertOk()
                ->assertSee('This product is currently out of stock.')
                ->assertDontSeeLivewire('storefront.add-to-cart');
        }
    }

    public function test_unavailable_products_return_404(): void
    {
        Product::factory()->for($this->category)->archived()->create(['slug' => 'archived']);
        Product::factory()->for($this->category)->draft()->create(['slug' => 'draft']);
        $this->product(['slug' => 'hidden'], Category::factory()->inactive()->create());

        foreach (['archived', 'draft', 'hidden', 'nope'] as $slug) {
            $this->get(route('storefront.product', $slug))->assertNotFound();
        }
    }

    public function test_product_pages_escape_user_content(): void
    {
        $this->product(['slug' => 'xss', 'name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(2)>']);

        $this->get(route('storefront.product', 'xss'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }

    private function countQueries(\Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
