<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrandManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- Listing --------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.brands.index'))->assertRedirect(route('login'));
    }

    public function test_brands_are_listed_alphabetically_with_product_counts(): void
    {
        $zeta = Brand::factory()->create(['name' => 'Zeta Brand']);
        Brand::factory()->create(['name' => 'Alpha Brand']);
        Product::factory()->for($zeta)->count(2)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.brands.index'))
            ->assertOk()
            ->assertSeeInOrder(['Alpha Brand', 'Zeta Brand']);
    }

    public function test_brands_can_be_searched_and_filtered_by_status(): void
    {
        Brand::factory()->create(['name' => 'Walton']);
        Brand::factory()->create(['name' => 'Samsung']);
        Brand::factory()->create(['name' => 'Wallmart Retired', 'is_active' => false]);
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->get(route('admin.brands.index', ['search' => 'wal']))
            ->assertSee('Walton')
            ->assertSee('Wallmart Retired')
            ->assertDontSee('Samsung');

        $this->actingAs($manager)
            ->get(route('admin.brands.index', ['search' => 'wal', 'status' => 'active']))
            ->assertSee('Walton')
            ->assertDontSee('Wallmart Retired');
    }

    public function test_search_treats_sql_wildcards_literally(): void
    {
        Brand::factory()->create(['name' => 'Acme']);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.brands.index', ['search' => '%']))
            ->assertOk()
            ->assertDontSee('Acme')
            ->assertSee('No brands match your filters.');
    }

    public function test_the_list_is_paginated(): void
    {
        Brand::factory()->count(30)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.brands.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('brands', fn ($brands) => $brands->count() === 5 && $brands->currentPage() === 2);
    }

    public function test_staff_can_view_but_not_manage_brands(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.brands.index'))
            ->assertOk()
            ->assertSee($brand->name)
            ->assertDontSee('New brand')
            ->assertDontSee(route('admin.brands.edit', $brand));
    }

    // --- Creating ---------------------------------------------------------------

    public function test_a_manager_can_create_a_brand_with_a_generated_slug(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.brands.store'), ['name' => 'Bata Shoes', 'description' => 'Footwear', 'is_active' => '1'])
            ->assertRedirect(route('admin.brands.index'))
            ->assertSessionHas('success', 'Brand “Bata Shoes” created.');

        $this->assertDatabaseHas('brands', ['name' => 'Bata Shoes', 'slug' => 'bata-shoes', 'is_active' => true]);
    }

    public function test_generated_slugs_are_made_unique(): void
    {
        Brand::factory()->create(['slug' => 'apex']);

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.brands.store'), ['name' => 'Apex', 'is_active' => '1']);

        $this->assertDatabaseHas('brands', ['slug' => 'apex-2']);
    }

    public function test_names_without_latin_characters_still_get_a_slug(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.brands.store'), ['name' => 'আড়ং', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', Brand::firstWhere('name', 'আড়ং')->slug);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBrandInput(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('b', 151)], 'name'],
            'invalid slug' => [['name' => 'Valid', 'slug' => 'Bad Slug!'], 'slug'],
            'description too long' => [['name' => 'Valid', 'description' => str_repeat('d', 5001)], 'description'],
        ];
    }

    #[DataProvider('invalidBrandInput')]
    public function test_invalid_input_is_rejected(array $input, string $field): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.brands.store'), $input + ['is_active' => '1'])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('brands', 0);
    }

    public function test_an_explicit_duplicate_slug_is_rejected(): void
    {
        Brand::factory()->create(['slug' => 'walton']);

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.brands.store'), ['name' => 'Walton BD', 'slug' => 'walton', 'is_active' => '1'])
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('brands', 1);
    }

    public function test_staff_cannot_create_brands(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.brands.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.brands.store'), ['name' => 'Nope', 'is_active' => '1'])->assertForbidden();

        $this->assertDatabaseCount('brands', 0);
    }

    // --- Updating ---------------------------------------------------------------

    public function test_a_brand_can_be_updated(): void
    {
        $brand = Brand::factory()->create(['name' => 'Old', 'slug' => 'old']);

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.brands.update', $brand), ['name' => 'New', 'slug' => 'new', 'description' => 'Updated', 'is_active' => '0'])
            ->assertRedirect(route('admin.brands.index'))
            ->assertSessionHas('success');

        $brand->refresh();
        $this->assertSame('New', $brand->name);
        $this->assertSame('new', $brand->slug);
        $this->assertSame('Updated', $brand->description);
        $this->assertFalse($brand->is_active);
    }

    public function test_keeping_the_same_slug_on_update_is_allowed(): void
    {
        $brand = Brand::factory()->create(['slug' => 'samsung']);

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.brands.update', $brand), ['name' => 'Samsung', 'slug' => 'samsung', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
    }

    public function test_updating_to_another_brands_slug_is_rejected(): void
    {
        Brand::factory()->create(['slug' => 'taken']);
        $brand = Brand::factory()->create(['slug' => 'mine']);

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.brands.update', $brand), ['name' => 'Mine', 'slug' => 'taken', 'is_active' => '1'])
            ->assertSessionHasErrors('slug');

        $this->assertSame('mine', $brand->refresh()->slug);
    }

    public function test_staff_cannot_edit_brands(): void
    {
        $brand = Brand::factory()->create(['name' => 'Original']);
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.brands.edit', $brand))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.brands.update', $brand), ['name' => 'Changed', 'is_active' => '1'])->assertForbidden();

        $this->assertSame('Original', $brand->refresh()->name);
    }

    // --- Activation ------------------------------------------------------------

    public function test_a_brand_can_be_deactivated_and_reactivated(): void
    {
        $brand = Brand::factory()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->patch(route('admin.brands.status', $brand), ['is_active' => '0'])
            ->assertSessionHas('success', "Brand “{$brand->name}” deactivated.");
        $this->assertFalse($brand->refresh()->is_active);

        $this->actingAs($manager)
            ->patch(route('admin.brands.status', $brand), ['is_active' => '1'])
            ->assertSessionHas('success', "Brand “{$brand->name}” activated.");
        $this->assertTrue($brand->refresh()->is_active);
    }

    public function test_status_requires_a_value(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->patch(route('admin.brands.status', $brand), [])
            ->assertSessionHasErrors('is_active');
    }

    public function test_staff_cannot_change_brand_status(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.brands.status', $brand), ['is_active' => '0'])
            ->assertForbidden();

        $this->assertTrue($brand->refresh()->is_active);
    }

    // --- Deleting ----------------------------------------------------------------

    public function test_an_admin_can_delete_an_unused_brand(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.brands.destroy', $brand))
            ->assertRedirect(route('admin.brands.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($brand);
    }

    public function test_a_brand_with_products_cannot_be_deleted(): void
    {
        $product = Product::factory()->for(Brand::factory())->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from(route('admin.brands.edit', $product->brand))
            ->delete(route('admin.brands.destroy', $product->brand))
            ->assertRedirect(route('admin.brands.edit', $product->brand))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'has products'));

        $this->assertModelExists($product->brand);
    }

    public function test_a_brand_used_only_by_archived_products_still_cannot_be_deleted(): void
    {
        $product = Product::factory()->for(Brand::factory())->archived()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.brands.destroy', $product->brand))
            ->assertSessionHas('error');

        $this->assertModelExists($product->brand);
    }

    public function test_the_edit_page_explains_why_a_brand_cannot_be_deleted(): void
    {
        $product = Product::factory()->for(Brand::factory())->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.brands.edit', $product->brand))
            ->assertOk()
            ->assertSee('cannot be deleted because 1 product uses it');
    }

    public function test_managers_cannot_delete_brands(): void
    {
        $brand = Brand::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->delete(route('admin.brands.destroy', $brand))
            ->assertForbidden();

        $this->assertModelExists($brand);
    }
}
