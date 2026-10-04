<?php

namespace Tests\Feature\Admin;

use App\Exceptions\Catalog\InvalidCategoryParentException;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- Listing --------------------------------------------------------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
    }

    public function test_the_list_shows_the_hierarchy_in_tree_order(): void
    {
        $root = Category::factory()->create(['name' => 'Electronics']);
        $child = Category::factory()->childOf($root)->create(['name' => 'Phones']);
        Category::factory()->childOf($child)->create(['name' => 'Android']);
        Category::factory()->create(['name' => 'Fashion', 'sort_order' => 1]);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSeeInOrder(['Electronics', 'Phones', 'Android', 'Fashion'])
            ->assertSee('1 subcategory');
    }

    public function test_search_shows_matching_categories_with_their_full_path(): void
    {
        $root = Category::factory()->create(['name' => 'Electronics']);
        Category::factory()->childOf($root)->create(['name' => 'Phones']);
        Category::factory()->create(['name' => 'Fashion']);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.categories.index', ['search' => 'phon']))
            ->assertOk()
            ->assertSee('Electronics › Phones')
            ->assertDontSee('Fashion');
    }

    public function test_status_filter(): void
    {
        Category::factory()->create(['name' => 'Visible Category']);
        Category::factory()->inactive()->create(['name' => 'Hidden Category']);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.categories.index', ['status' => 'inactive']))
            ->assertSee('Hidden Category')
            ->assertDontSee('Visible Category');
    }

    public function test_staff_can_view_but_not_manage_categories(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee($category->name)
            ->assertDontSee('New category')
            ->assertDontSee(route('admin.categories.edit', $category))
            ->assertDontSee('Deactivate');
    }

    // --- Creating ---------------------------------------------------------------

    public function test_a_manager_can_create_a_top_level_category_with_a_generated_slug(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.categories.store'), [
                'name' => 'Home & Kitchen',
                'description' => 'Everything for the home.',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'name' => 'Home & Kitchen',
            'slug' => 'home-kitchen',
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_generated_slugs_are_made_unique(): void
    {
        Category::factory()->create(['name' => 'Shoes', 'slug' => 'shoes']);

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.categories.store'), ['name' => 'Shoes', 'is_active' => '1']);

        $this->assertDatabaseHas('categories', ['slug' => 'shoes-2']);
    }

    public function test_an_unchecked_active_box_creates_an_inactive_category(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.categories.store'), ['name' => 'Seasonal', 'is_active' => '0']);

        $this->assertFalse(Category::firstWhere('name', 'Seasonal')->is_active);
    }

    public function test_categories_can_be_nested_three_levels_deep(): void
    {
        $user = User::factory()->manager()->create();
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();

        $this->actingAs($user)
            ->post(route('admin.categories.store'), ['name' => 'Level Three', 'parent_id' => $child->id, 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $grandchild = Category::firstWhere('name', 'Level Three');
        $this->assertTrue($grandchild->parent->is($child));
        $this->assertTrue($grandchild->parent->parent->is($root));
    }

    public function test_a_fourth_level_category_is_rejected(): void
    {
        $level3 = Category::factory()->childOf(
            Category::factory()->childOf(Category::factory()->create())->create()
        )->create();

        $this->actingAs(User::factory()->manager()->create())
            ->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), ['name' => 'Level Four', 'parent_id' => $level3->id, 'is_active' => '1'])
            ->assertRedirect(route('admin.categories.create'))
            ->assertSessionHasErrors(['parent_id' => 'Categories can be nested at most 3 levels deep.']);

        $this->assertDatabaseMissing('categories', ['name' => 'Level Four']);
    }

    public function test_the_service_enforces_the_depth_limit_for_non_http_callers(): void
    {
        $level3 = Category::factory()->childOf(
            Category::factory()->childOf(Category::factory()->create())->create()
        )->create();

        $this->expectException(InvalidCategoryParentException::class);

        app(CategoryService::class)->create(['name' => 'Level Four', 'parent_id' => $level3->id]);
    }

    public function test_the_create_form_only_offers_valid_parents(): void
    {
        $root = Category::factory()->create(['name' => 'Root Level']);
        $child = Category::factory()->childOf($root)->create(['name' => 'Second Level']);
        Category::factory()->childOf($child)->create(['name' => 'Third Level']);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSee('Root Level › Second Level')
            ->assertDontSee('Second Level › Third Level');
    }

    public function test_the_create_form_can_preselect_a_parent(): void
    {
        $root = Category::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.categories.create', ['parent' => $root->id]))
            ->assertOk()
            ->assertSee('<option value="'.$root->id.'" selected', false);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCategoryInput(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 151)], 'name'],
            'slug with spaces' => [['name' => 'Valid', 'slug' => 'not a slug'], 'slug'],
            'slug with double hyphen' => [['name' => 'Valid', 'slug' => 'bad--slug'], 'slug'],
            'unknown parent' => [['name' => 'Valid', 'parent_id' => 999999], 'parent_id'],
            'negative sort order' => [['name' => 'Valid', 'sort_order' => -1], 'sort_order'],
        ];
    }

    #[DataProvider('invalidCategoryInput')]
    public function test_invalid_input_is_rejected(array $input, string $field): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.categories.store'), $input + ['is_active' => '1'])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_an_explicit_duplicate_slug_is_rejected(): void
    {
        Category::factory()->create(['slug' => 'phones']);

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('admin.categories.store'), ['name' => 'Mobile Phones', 'slug' => 'Phones', 'is_active' => '1'])
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('categories', 1);
    }

    public function test_staff_cannot_create_categories(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.categories.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.categories.store'), ['name' => 'Nope', 'is_active' => '1'])->assertForbidden();

        $this->assertDatabaseCount('categories', 0);
    }

    // --- Updating ---------------------------------------------------------------

    public function test_a_category_can_be_updated(): void
    {
        $category = Category::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);
        $newParent = Category::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.categories.update', $category), [
                'name' => 'New Name',
                'slug' => 'new-name',
                'parent_id' => $newParent->id,
                'sort_order' => 3,
                'is_active' => '1',
                'meta_title' => 'SEO title',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $category->refresh();
        $this->assertSame('New Name', $category->name);
        $this->assertSame('new-name', $category->slug);
        $this->assertTrue($category->parent->is($newParent));
        $this->assertSame(3, $category->sort_order);
        $this->assertSame('SEO title', $category->meta_title);
    }

    public function test_keeping_the_same_slug_on_update_is_allowed(): void
    {
        $category = Category::factory()->create(['slug' => 'phones']);

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.categories.update', $category), ['name' => 'Phones', 'slug' => 'phones', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
    }

    public function test_updating_to_another_categorys_slug_is_rejected(): void
    {
        Category::factory()->create(['slug' => 'taken']);
        $category = Category::factory()->create(['slug' => 'mine']);

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.categories.update', $category), ['name' => 'Mine', 'slug' => 'taken', 'is_active' => '1'])
            ->assertSessionHasErrors('slug');

        $this->assertSame('mine', $category->refresh()->slug);
    }

    public function test_a_category_cannot_become_its_own_parent(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.categories.update', $category), ['name' => $category->name, 'parent_id' => $category->id, 'is_active' => '1'])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($category->refresh()->parent_id);
    }

    public function test_a_category_cannot_move_under_its_own_descendant(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.categories.update', $root), ['name' => $root->name, 'parent_id' => $child->id, 'is_active' => '1'])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($root->refresh()->parent_id);
    }

    public function test_moving_a_category_with_subcategories_cannot_exceed_three_levels(): void
    {
        $phones = Category::factory()->create();
        Category::factory()->childOf($phones)->create();
        $laptops = Category::factory()->childOf(Category::factory()->create())->create();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.categories.update', $phones), ['name' => $phones->name, 'parent_id' => $laptops->id, 'is_active' => '1'])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($phones->refresh()->parent_id);
    }

    public function test_a_subcategory_can_be_moved_back_to_the_top_level(): void
    {
        $child = Category::factory()->childOf(Category::factory()->create())->create();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('admin.categories.update', $child), ['name' => $child->name, 'parent_id' => '', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNull($child->refresh()->parent_id);
    }

    public function test_staff_cannot_edit_categories(): void
    {
        $category = Category::factory()->create(['name' => 'Original']);
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.categories.edit', $category))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.categories.update', $category), ['name' => 'Changed', 'is_active' => '1'])->assertForbidden();

        $this->assertSame('Original', $category->refresh()->name);
    }

    // --- Activation ------------------------------------------------------------

    public function test_a_category_can_be_deactivated_and_reactivated(): void
    {
        $category = Category::factory()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->patch(route('admin.categories.status', $category), ['is_active' => '0'])
            ->assertRedirect()
            ->assertSessionHas('success', "Category “{$category->name}” deactivated.");
        $this->assertFalse($category->refresh()->is_active);

        $this->actingAs($manager)->patch(route('admin.categories.status', $category), ['is_active' => '1']);
        $this->assertTrue($category->refresh()->is_active);
    }

    public function test_deactivating_a_parent_does_not_modify_its_subcategories(): void
    {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->patch(route('admin.categories.status', $root), ['is_active' => '0']);

        $this->assertTrue($child->refresh()->is_active);
    }

    public function test_status_requires_a_boolean(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->patch(route('admin.categories.status', $category), ['is_active' => 'maybe'])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($category->refresh()->is_active);
    }

    public function test_staff_cannot_change_category_status(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.categories.status', $category), ['is_active' => '0'])
            ->assertForbidden();

        $this->assertTrue($category->refresh()->is_active);
    }

    // --- Deleting ----------------------------------------------------------------

    public function test_an_admin_can_delete_an_unused_category(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($category);
    }

    public function test_a_category_with_subcategories_cannot_be_deleted(): void
    {
        $root = Category::factory()->create();
        Category::factory()->childOf($root)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.categories.edit', $root))
            ->delete(route('admin.categories.destroy', $root))
            ->assertRedirect(route('admin.categories.edit', $root))
            ->assertSessionHas('error');

        $this->assertModelExists($root);
    }

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('admin.categories.destroy', $product->category))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'has products'));

        $this->assertModelExists($product->category);
    }

    public function test_api_clients_receive_409_when_deleting_a_category_in_use(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('admin.categories.destroy', $product->category))
            ->assertConflict()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'has products'));
    }

    public function test_the_edit_page_explains_why_a_category_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.categories.edit', $product->category))
            ->assertOk()
            ->assertSee('cannot be deleted')
            ->assertDontSee('Delete category</button>', false);
    }

    public function test_managers_cannot_delete_categories(): void
    {
        $category = Category::factory()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->delete(route('admin.categories.destroy', $category))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.categories.edit', $category))->assertDontSee('Delete category');

        $this->assertModelExists($category);
    }
}
