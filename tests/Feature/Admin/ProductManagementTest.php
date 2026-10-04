<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Catalog\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create(['name' => 'Shirts']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cotton T-Shirt',
            'sku' => 'TSHIRT-001',
            'category_id' => $this->category->id,
            'brand_id' => null,
            'short_description' => 'Soft and breathable.',
            'description' => 'A comfortable cotton t-shirt.',
            'purchase_price' => '250.00',
            'selling_price' => '500.00',
            'discount_price' => '',
            'manage_stock' => '1',
            'opening_stock' => '20',
            'low_stock_threshold' => '5',
            'status' => 'active',
            'is_featured' => '0',
            'weight' => '0.250',
            'length' => '30',
            'width' => '20',
            'height' => '2',
            'meta_title' => '',
            'meta_description' => '',
        ], $overrides);
    }

    private function manager(): User
    {
        return User::factory()->manager()->create();
    }

    // --- Listing ------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.products.index'))->assertRedirect(route('login'));
    }

    public function test_the_list_shows_key_product_information(): void
    {
        $brand = Brand::factory()->create(['name' => 'Aarong']);
        Product::factory()->for($this->category)->for($brand)->discounted('450.00')->create([
            'name' => 'Kurta', 'sku' => 'KURTA-9', 'selling_price' => '600.00', 'is_featured' => true,
        ]);

        $this->actingAs($this->manager())
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('Kurta')
            ->assertSee('KURTA-9')
            ->assertSee('Shirts')
            ->assertSee('Aarong')
            ->assertSee('৳450.00')
            ->assertSee('৳600.00')
            ->assertSee('In stock')
            ->assertSee('Active')
            ->assertSee('Featured');
    }

    public function test_search_matches_name_sku_and_slug(): void
    {
        Product::factory()->create(['name' => 'Blue Denim Jacket', 'sku' => 'JKT-1', 'slug' => 'blue-denim-jacket']);
        Product::factory()->create(['name' => 'Red Saree', 'sku' => 'SAREE-77', 'slug' => 'red-saree']);
        $user = $this->manager();

        $this->actingAs($user)->get(route('admin.products.index', ['search' => 'denim']))->assertSee('Blue Denim Jacket')->assertDontSee('Red Saree');
        $this->actingAs($user)->get(route('admin.products.index', ['search' => 'saree-77']))->assertSee('Red Saree')->assertDontSee('Blue Denim Jacket');
    }

    public function test_the_list_is_paginated(): void
    {
        Product::factory()->count(30)->for($this->category)->create();

        $this->actingAs($this->manager())
            ->get(route('admin.products.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->total() === 30 && $products->count() === 5);
    }

    public function test_category_filter_includes_subcategories(): void
    {
        $child = Category::factory()->childOf($this->category)->create();
        $other = Category::factory()->create();
        Product::factory()->for($this->category)->create(['name' => 'Parent Product']);
        Product::factory()->for($child)->create(['name' => 'Child Product']);
        Product::factory()->for($other)->create(['name' => 'Other Product']);

        $this->actingAs($this->manager())
            ->get(route('admin.products.index', ['category' => $this->category->id]))
            ->assertSee('Parent Product')
            ->assertSee('Child Product')
            ->assertDontSee('Other Product');
    }

    public function test_brand_filter(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->for($brand)->create(['name' => 'Branded Item']);
        Product::factory()->create(['name' => 'Generic Item']);

        $this->actingAs($this->manager())
            ->get(route('admin.products.index', ['brand' => $brand->id]))
            ->assertSee('Branded Item')
            ->assertDontSee('Generic Item');
    }

    public function test_status_filter(): void
    {
        Product::factory()->create(['name' => 'Live Item']);
        Product::factory()->draft()->create(['name' => 'Draft Item']);
        Product::factory()->archived()->create(['name' => 'Old Item']);

        $this->actingAs($this->manager())
            ->get(route('admin.products.index', ['status' => 'draft']))
            ->assertSee('Draft Item')
            ->assertDontSee('Live Item')
            ->assertDontSee('Old Item');
    }

    public function test_stock_filters(): void
    {
        Product::factory()->withStock(50)->create(['name' => 'Plenty Item', 'low_stock_threshold' => 5]);
        Product::factory()->withStock(3)->create(['name' => 'Scarce Item', 'low_stock_threshold' => 5]);
        Product::factory()->outOfStock()->create(['name' => 'Gone Item']);
        $user = $this->manager();

        $this->actingAs($user)->get(route('admin.products.index', ['stock' => 'low']))
            ->assertSee('Scarce Item')->assertDontSee('Plenty Item')->assertDontSee('Gone Item');
        $this->actingAs($user)->get(route('admin.products.index', ['stock' => 'out_of_stock']))
            ->assertSee('Gone Item')->assertDontSee('Plenty Item');
    }

    public function test_staff_can_view_products_but_not_manage_them(): void
    {
        $product = Product::factory()->create();
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.products.index'))
            ->assertOk()->assertDontSee('New product')->assertDontSee(route('admin.products.edit', $product));

        $this->actingAs($staff)->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee($product->name)
            ->assertDontSee('Record stock change')
            ->assertDontSee('Upload images')
            ->assertDontSee('Move to draft')
            ->assertDontSee('Delete product');
    }

    // --- Viewing ------------------------------------------------------------

    public function test_the_details_page_shows_the_product(): void
    {
        $product = Product::factory()->for(Category::factory()->childOf($this->category)->state(['name' => 'Polo']))->create([
            'name' => 'Polo Shirt', 'sku' => 'POLO-1', 'description' => 'Line one',
        ]);

        $this->actingAs($this->manager())
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('Polo Shirt')
            ->assertSee('SKU POLO-1 · Shirts › Polo')
            ->assertSee('Line one')
            ->assertSee('Record stock change');
    }

    // --- Creating -------------------------------------------------------------

    public function test_a_manager_can_create_a_product(): void
    {
        $response = $this->actingAs($this->manager())->post(route('admin.products.store'), $this->payload());

        $product = Product::firstWhere('sku', 'TSHIRT-001');
        $response->assertRedirect(route('admin.products.show', $product))->assertSessionHas('success');

        $this->assertSame('Cotton T-Shirt', $product->name);
        $this->assertSame('cotton-t-shirt', $product->slug);
        $this->assertSame('500.00', $product->selling_price);
        $this->assertSame('250.00', $product->purchase_price);
        $this->assertNull($product->discount_price);
        $this->assertSame(ProductStatus::Active, $product->status);
        $this->assertSame(20, $product->stock_quantity);
        $this->assertSame(5, $product->low_stock_threshold);
        $this->assertSame('0.250', $product->weight);
        $this->assertFalse($product->is_featured);
    }

    public function test_an_explicit_slug_is_used(): void
    {
        $this->actingAs($this->manager())->post(route('admin.products.store'), $this->payload(['slug' => 'My-Custom-Slug']));

        $this->assertDatabaseHas('products', ['sku' => 'TSHIRT-001', 'slug' => 'my-custom-slug']);
    }

    public function test_generated_slugs_are_made_unique(): void
    {
        Product::factory()->create(['slug' => 'cotton-t-shirt']);

        $this->actingAs($this->manager())->post(route('admin.products.store'), $this->payload());

        $this->assertDatabaseHas('products', ['sku' => 'TSHIRT-001', 'slug' => 'cotton-t-shirt-2']);
    }

    public function test_bangla_names_get_a_valid_slug(): void
    {
        $this->actingAs($this->manager())->post(route('admin.products.store'), $this->payload(['name' => 'জামদানি শাড়ি']))
            ->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', Product::firstWhere('sku', 'TSHIRT-001')->slug);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        Product::factory()->create(['sku' => 'TSHIRT-001']);

        $this->actingAs($this->manager())->post(route('admin.products.store'), $this->payload())->assertSessionHasErrors('sku');
        $this->actingAs($this->manager())->post(route('admin.products.store'), $this->payload(['sku' => 'tshirt-001']))->assertSessionHasErrors('sku');

        $this->assertSame(1, Product::count());
    }

    public function test_duplicate_explicit_slug_is_rejected(): void
    {
        Product::factory()->create(['slug' => 'taken-slug']);

        $this->actingAs($this->manager())->post(route('admin.products.store'), $this->payload(['slug' => 'taken-slug']))
            ->assertSessionHasErrors('slug');
    }

    public function test_a_lost_race_on_the_sku_index_becomes_a_validation_error(): void
    {
        // Simulates two requests passing validation at the same time: the database index decides.
        Product::factory()->create(['sku' => 'RACE-1']);

        try {
            app(ProductService::class)->create(['name' => 'Second', 'sku' => 'RACE-1', 'category_id' => $this->category->id, 'selling_price' => '10.00']);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }

        $this->assertSame(1, Product::where('sku', 'RACE-1')->count());
    }

    public function test_new_products_cannot_use_an_inactive_category_or_brand(): void
    {
        $inactiveCategory = Category::factory()->inactive()->create();
        $childOfInactive = Category::factory()->childOf($inactiveCategory)->create();
        $inactiveBrand = Brand::factory()->create(['is_active' => false]);
        $user = $this->manager();

        $this->actingAs($user)->post(route('admin.products.store'), $this->payload(['category_id' => $inactiveCategory->id]))->assertSessionHasErrors('category_id');
        $this->actingAs($user)->post(route('admin.products.store'), $this->payload(['category_id' => $childOfInactive->id]))->assertSessionHasErrors('category_id');
        $this->actingAs($user)->post(route('admin.products.store'), $this->payload(['brand_id' => $inactiveBrand->id]))->assertSessionHasErrors('brand_id');

        $this->assertSame(0, Product::count());
    }

    public function test_the_create_form_lists_only_selectable_categories_and_brands(): void
    {
        Category::factory()->inactive()->create(['name' => 'Hidden Category']);
        Brand::factory()->create(['name' => 'Retired Brand', 'is_active' => false]);
        Brand::factory()->create(['name' => 'Current Brand']);

        $this->actingAs($this->manager())
            ->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('Shirts')
            ->assertSee('Current Brand')
            ->assertDontSee('Hidden Category')
            ->assertDontSee('Retired Brand');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProductInput(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'missing sku' => [['sku' => ''], 'sku'],
            'sku with spaces' => [['sku' => 'BAD SKU'], 'sku'],
            'sku too long' => [['sku' => str_repeat('A', 65)], 'sku'],
            'missing category' => [['category_id' => ''], 'category_id'],
            'unknown category' => [['category_id' => 999999], 'category_id'],
            'unknown brand' => [['brand_id' => 999999], 'brand_id'],
            'invalid slug' => [['slug' => 'Not A Slug!'], 'slug'],
            'missing selling price' => [['selling_price' => ''], 'selling_price'],
            'non-numeric price' => [['selling_price' => 'abc'], 'selling_price'],
            'three decimal places' => [['selling_price' => '10.555'], 'selling_price'],
            'price too large' => [['selling_price' => '99999999999.00'], 'selling_price'],
            'negative selling price' => [['selling_price' => '-1'], 'selling_price'],
            'negative purchase price' => [['purchase_price' => '-0.01'], 'purchase_price'],
            'negative discount price' => [['discount_price' => '-5'], 'discount_price'],
            'discount equal to selling' => [['discount_price' => '500.00'], 'discount_price'],
            'discount above selling' => [['discount_price' => '500.01'], 'discount_price'],
            'invalid status' => [['status' => 'published'], 'status'],
            'negative opening stock' => [['opening_stock' => '-1'], 'opening_stock'],
            'fractional opening stock' => [['opening_stock' => '1.5'], 'opening_stock'],
            'negative weight' => [['weight' => '-1'], 'weight'],
            'negative threshold' => [['low_stock_threshold' => '-1'], 'low_stock_threshold'],
        ];
    }

    #[DataProvider('invalidProductInput')]
    public function test_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.products.store'), $this->payload($overrides))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Product::count());
    }

    public function test_a_valid_discount_is_accepted(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.products.store'), $this->payload(['discount_price' => '499.99']))
            ->assertSessionHasNoErrors();

        $product = Product::firstWhere('sku', 'TSHIRT-001');
        $this->assertSame('499.99', $product->discount_price);
        $this->assertSame('499.99', $product->effectivePrice());
    }

    public function test_free_products_are_allowed(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.products.store'), $this->payload(['selling_price' => '0', 'purchase_price' => '0']))
            ->assertSessionHasNoErrors();
    }

    public function test_staff_cannot_create_products(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.products.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, Product::count());
    }

    // --- Updating ---------------------------------------------------------------

    public function test_a_product_can_be_updated(): void
    {
        $product = Product::factory()->for($this->category)->withStock(7)->create(['sku' => 'OLD-SKU', 'slug' => 'old-slug']);
        $brand = Brand::factory()->create();

        $this->actingAs($this->manager())
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => 'Renamed Shirt',
                'sku' => 'NEW-SKU',
                'slug' => 'renamed-shirt',
                'brand_id' => $brand->id,
                'selling_price' => '800.00',
                'discount_price' => '650.00',
                'is_featured' => '1',
                'status' => 'draft',
                'opening_stock' => null,
            ]))
            ->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertSame('Renamed Shirt', $product->name);
        $this->assertSame('NEW-SKU', $product->sku);
        $this->assertSame('renamed-shirt', $product->slug);
        $this->assertTrue($product->brand->is($brand));
        $this->assertSame('650.00', $product->discount_price);
        $this->assertTrue($product->is_featured);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertSame(7, $product->stock_quantity);
    }

    public function test_a_product_keeps_its_own_sku_and_slug_on_update(): void
    {
        $product = Product::factory()->for($this->category)->create(['sku' => 'KEEP-1', 'slug' => 'keep-me']);

        $this->actingAs($this->manager())
            ->put(route('admin.products.update', $product), $this->payload(['sku' => 'KEEP-1', 'slug' => 'keep-me', 'opening_stock' => null]))
            ->assertSessionHasNoErrors();
    }

    public function test_updating_to_another_products_sku_is_rejected(): void
    {
        Product::factory()->create(['sku' => 'TAKEN']);
        $product = Product::factory()->for($this->category)->create(['sku' => 'MINE']);

        $this->actingAs($this->manager())
            ->put(route('admin.products.update', $product), $this->payload(['sku' => 'TAKEN', 'opening_stock' => null]))
            ->assertSessionHasErrors('sku');

        $this->assertSame('MINE', $product->refresh()->sku);
    }

    public function test_a_product_may_keep_its_now_inactive_category_and_brand(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->for($this->category)->for($brand)->create();
        $this->category->update(['is_active' => false]);
        $brand->update(['is_active' => false]);

        $this->actingAs($this->manager())
            ->put(route('admin.products.update', $product), $this->payload(['brand_id' => $brand->id, 'name' => 'Still Here', 'opening_stock' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Still Here', $product->refresh()->name);
    }

    public function test_the_edit_form_marks_an_inactive_current_category(): void
    {
        $product = Product::factory()->for($this->category)->create();
        $this->category->update(['is_active' => false]);

        $this->actingAs($this->manager())
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('Shirts (inactive)');
    }

    public function test_staff_cannot_update_products(): void
    {
        $product = Product::factory()->for($this->category)->create(['name' => 'Original']);
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.products.edit', $product))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.products.update', $product), $this->payload(['opening_stock' => null]))->assertForbidden();

        $this->assertSame('Original', $product->refresh()->name);
    }

    // --- Status -------------------------------------------------------------------

    public function test_product_status_can_be_published_unpublished_and_archived(): void
    {
        $product = Product::factory()->draft()->create();
        $user = $this->manager();

        foreach ([ProductStatus::Active, ProductStatus::Archived, ProductStatus::Draft] as $status) {
            $this->actingAs($user)
                ->patch(route('admin.products.status', $product), ['status' => $status->value])
                ->assertSessionHas('success', "Product “{$product->name}” is now {$status->label()}.");

            $this->assertSame($status, $product->refresh()->status);
        }
    }

    public function test_invalid_status_values_are_rejected(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->manager())
            ->patch(route('admin.products.status', $product), ['status' => 'deleted'])
            ->assertSessionHasErrors('status');

        $this->assertSame(ProductStatus::Active, $product->refresh()->status);
    }

    public function test_staff_cannot_change_product_status(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.products.status', $product), ['status' => 'archived'])
            ->assertForbidden();

        $this->assertSame(ProductStatus::Active, $product->refresh()->status);
    }

    // --- Deleting -------------------------------------------------------------------

    public function test_an_admin_can_delete_a_never_ordered_product_and_its_image_files(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.products.images.store', $product), ['images' => [UploadedFile::fake()->image('a.jpg', 400, 400)]]);
        $path = ProductImage::firstOrFail()->path;
        Storage::disk('public')->assertExists($path);

        $this->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($product);
        $this->assertDatabaseCount('product_images', 0);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_an_ordered_product_cannot_be_deleted(): void
    {
        $item = OrderItem::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('admin.products.destroy', $item->product))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Archive it instead'));

        $this->assertModelExists($item->product);
    }

    public function test_the_details_page_explains_why_an_ordered_product_cannot_be_deleted(): void
    {
        $item = OrderItem::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.products.show', $item->product))
            ->assertSee('must be kept for order history')
            ->assertDontSee('Delete product</button>', false);
    }

    public function test_managers_cannot_delete_products(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->manager())->delete(route('admin.products.destroy', $product))->assertForbidden();

        $this->assertModelExists($product);
    }
}
