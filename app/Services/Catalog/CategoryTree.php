<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * In-memory view of the whole category hierarchy.
 *
 * The category table is small, so loading it once and walking it in PHP is
 * cheaper and simpler than recursive queries. This class is the single source
 * of truth for depth limits and invalid-parent (cycle) rules.
 */
final class CategoryTree
{
    /** @var Collection<int, Category> */
    private Collection $categories;

    /** @var array<int, list<int>> */
    private array $childIds = [];

    /**
     * @param  Collection<int, Category>  $categories
     */
    public function __construct(Collection $categories)
    {
        $this->categories = $categories
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->keyBy('id');

        foreach ($this->categories as $category) {
            $this->childIds[$category->parent_id ?? 0][] = $category->id;
        }
    }

    public static function load(): self
    {
        return new self(Category::query()->withCount(['products', 'children'])->get());
    }

    public function find(int $id): ?Category
    {
        return $this->categories->get($id);
    }

    /**
     * Level of a category: 1 for a root category.
     */
    public function depth(int $id): int
    {
        $depth = 0;
        $visited = [];

        while ($id && ($category = $this->find($id)) && ! isset($visited[$id])) {
            $visited[$id] = true;
            $depth++;
            $id = $category->parent_id;
        }

        return $depth;
    }

    /**
     * Number of levels in the subtree rooted at this category: 1 for a leaf.
     */
    public function height(int $id): int
    {
        $childHeights = array_map(fn (int $childId) => $this->height($childId), $this->childIds[$id] ?? []);

        return 1 + ($childHeights === [] ? 0 : max($childHeights));
    }

    /**
     * @return list<int>
     */
    public function descendantIds(int $id): array
    {
        $ids = [];

        foreach ($this->childIds[$id] ?? [] as $childId) {
            $ids[] = $childId;
            array_push($ids, ...$this->descendantIds($childId));
        }

        return $ids;
    }

    /**
     * Human-readable path, e.g. "Electronics › Phones › Android".
     */
    public function path(int $id): string
    {
        $names = [];
        $visited = [];

        while ($id && ($category = $this->find($id)) && ! isset($visited[$id])) {
            $visited[$id] = true;
            array_unshift($names, $category->name);
            $id = $category->parent_id;
        }

        return implode(' › ', $names);
    }

    /**
     * Why $parentId cannot be the parent of $categoryId (null = new category),
     * or null when the assignment is valid.
     */
    public function parentError(?int $categoryId, ?int $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        if (! $this->find($parentId)) {
            return 'The selected parent category does not exist.';
        }

        if ($categoryId !== null && ($parentId === $categoryId || in_array($parentId, $this->descendantIds($categoryId), true))) {
            return 'A category cannot be placed under itself or one of its own subcategories.';
        }

        $subtreeHeight = $categoryId !== null ? $this->height($categoryId) : 1;

        if ($this->depth($parentId) + $subtreeHeight > Category::MAX_DEPTH) {
            return $subtreeHeight > 1
                ? 'Moving this category (and its subcategories) under the selected parent would exceed the maximum of '.Category::MAX_DEPTH.' levels.'
                : 'Categories can be nested at most '.Category::MAX_DEPTH.' levels deep.';
        }

        return null;
    }

    /**
     * Every category in display order (parents before their children).
     *
     * @return list<array{category: Category, depth: int}>
     */
    public function flattened(): array
    {
        $rows = [];
        $walk = function (int $parentKey, int $depth) use (&$walk, &$rows): void {
            foreach ($this->childIds[$parentKey] ?? [] as $id) {
                $rows[] = ['category' => $this->categories[$id], 'depth' => $depth];
                $walk($id, $depth + 1);
            }
        };

        $walk(0, 1);

        return $rows;
    }

    /**
     * Whether the category and all of its ancestors are active (i.e. it is
     * visible on the storefront).
     */
    public function isEffectivelyActive(int $id): bool
    {
        $visited = [];
        $currentId = $id;

        while ($currentId !== null) {
            $category = $this->find($currentId);

            if (! $category || ! $category->is_active || isset($visited[$currentId])) {
                return false;
            }

            $visited[$currentId] = true;
            $currentId = $category->parent_id;
        }

        return true;
    }

    /**
     * Categories a product may be assigned to: effectively active ones, plus
     * $alwaysInclude (a product's current category) even if it is inactive.
     *
     * @return array<int, string> id => path
     */
    public function productCategoryOptions(?int $alwaysInclude = null): array
    {
        $options = [];

        foreach ($this->flattened() as $row) {
            $id = $row['category']->id;

            if ($this->isEffectivelyActive($id)) {
                $options[$id] = $this->path($id);
            } elseif ($id === $alwaysInclude) {
                $options[$id] = $this->path($id).' (inactive)';
            }
        }

        return $options;
    }

    /**
     * Every category as id => path, in display order (for filters).
     *
     * @return array<int, string>
     */
    public function allOptions(): array
    {
        $options = [];

        foreach ($this->flattened() as $row) {
            $options[$row['category']->id] = $this->path($row['category']->id);
        }

        return $options;
    }

    /**
     * Ids of a category and all its descendants (for "products in this category" filters).
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(int $id): array
    {
        return [$id, ...$this->descendantIds($id)];
    }

    /**
     * Categories that may be chosen as the parent of $categoryId (null = new category).
     *
     * @return array<int, string> id => path
     */
    public function parentOptions(?int $categoryId = null): array
    {
        $options = [];

        foreach ($this->flattened() as $row) {
            $id = $row['category']->id;

            if ($this->parentError($categoryId, $id) === null) {
                $options[$id] = $this->path($id);
            }
        }

        return $options;
    }
}
