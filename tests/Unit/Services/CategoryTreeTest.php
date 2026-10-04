<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Services\Catalog\CategoryTree;
use PHPUnit\Framework\TestCase;

/**
 * Hierarchy rules, tested against an in-memory tree:
 *
 *   1 Electronics
 *   ├── 2 Phones
 *   │   └── 3 Android
 *   └── 4 Laptops
 *   5 Fashion
 */
class CategoryTreeTest extends TestCase
{
    private CategoryTree $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tree = new CategoryTree(collect([
            $this->category(1, null, 'Electronics'),
            $this->category(2, 1, 'Phones'),
            $this->category(3, 2, 'Android'),
            $this->category(4, 1, 'Laptops', sortOrder: 5),
            $this->category(5, null, 'Fashion'),
        ]));
    }

    public function test_depth_counts_levels_from_the_root(): void
    {
        $this->assertSame(1, $this->tree->depth(1));
        $this->assertSame(2, $this->tree->depth(2));
        $this->assertSame(3, $this->tree->depth(3));
    }

    public function test_height_counts_levels_in_the_subtree(): void
    {
        $this->assertSame(3, $this->tree->height(1));
        $this->assertSame(2, $this->tree->height(2));
        $this->assertSame(1, $this->tree->height(3));
        $this->assertSame(1, $this->tree->height(5));
    }

    public function test_descendants_and_paths(): void
    {
        $this->assertEqualsCanonicalizing([2, 3, 4], $this->tree->descendantIds(1));
        $this->assertSame('Electronics › Phones › Android', $this->tree->path(3));
    }

    public function test_flattened_lists_parents_before_children_in_sort_order(): void
    {
        $rows = array_map(fn (array $row) => [$row['category']->id, $row['depth']], $this->tree->flattened());

        $this->assertSame([[1, 1], [2, 2], [3, 3], [4, 2], [5, 1]], $rows);
    }

    public function test_new_categories_may_be_created_at_levels_one_to_three(): void
    {
        $this->assertNull($this->tree->parentError(null, null));
        $this->assertNull($this->tree->parentError(null, 1));
        $this->assertNull($this->tree->parentError(null, 2));
    }

    public function test_a_fourth_level_is_rejected(): void
    {
        $this->assertSame('Categories can be nested at most 3 levels deep.', $this->tree->parentError(null, 3));
    }

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $this->assertStringContainsString('itself', $this->tree->parentError(2, 2));
    }

    public function test_a_category_cannot_move_under_its_own_descendant(): void
    {
        $this->assertStringContainsString('subcategories', $this->tree->parentError(1, 3));
        $this->assertStringContainsString('subcategories', $this->tree->parentError(1, 4));
    }

    public function test_moving_a_subtree_counts_its_height(): void
    {
        // Phones (height 2) under Laptops (depth 2) would put Android at level 4.
        $this->assertStringContainsString('maximum of 3 levels', $this->tree->parentError(2, 4));
        // Phones under Fashion (depth 1) keeps Android at level 3.
        $this->assertNull($this->tree->parentError(2, 5));
        // Electronics (height 3) cannot go under anything.
        $this->assertNotNull($this->tree->parentError(1, 5));
    }

    public function test_unknown_parents_are_rejected(): void
    {
        $this->assertSame('The selected parent category does not exist.', $this->tree->parentError(null, 999));
    }

    public function test_parent_options_for_a_new_category_exclude_the_deepest_level(): void
    {
        $this->assertSame([1, 2, 4, 5], array_keys($this->tree->parentOptions()));
        $this->assertSame('Electronics › Phones', $this->tree->parentOptions()[2]);
    }

    public function test_parent_options_when_editing_exclude_self_descendants_and_too_deep_targets(): void
    {
        $this->assertSame([1, 5], array_keys($this->tree->parentOptions(2)));
        $this->assertSame([], $this->tree->parentOptions(1));
    }

    private function category(int $id, ?int $parentId, string $name, int $sortOrder = 0): Category
    {
        return (new Category)->forceFill([
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => strtolower($name),
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }
}
