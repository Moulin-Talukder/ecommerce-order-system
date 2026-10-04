<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\CannotDeleteException;
use App\Exceptions\Catalog\InvalidCategoryParentException;
use App\Models\Category;
use App\Support\Slug;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Write operations for categories. All hierarchy rules (max depth, no cycles)
 * are enforced here via {@see CategoryTree}, regardless of the caller.
 */
class CategoryService
{
    /**
     * @param  array{name: string, slug?: string|null, parent_id?: int|null, description?: string|null, is_active?: bool, sort_order?: int|null, meta_title?: string|null, meta_description?: string|null}  $data
     */
    public function create(array $data): Category
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data): Category {
            $this->assertValidParent(null, $data['parent_id']);

            $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Category::class, $data['name']);

            return Category::create($data);
        });
    }

    /**
     * @param  array{name: string, slug?: string|null, parent_id?: int|null, description?: string|null, is_active?: bool, sort_order?: int|null, meta_title?: string|null, meta_description?: string|null}  $data
     */
    public function update(Category $category, array $data): Category
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($category, $data): Category {
            $this->assertValidParent($category->id, $data['parent_id']);

            $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Category::class, $data['name'], $category->id);
            $category->update($data);

            return $category;
        });
    }

    /**
     * Deactivating a category does not change its subcategories; the storefront
     * treats a category as visible only when it and all its ancestors are active.
     */
    public function setActive(Category $category, bool $active): Category
    {
        $category->update(['is_active' => $active]);

        return $category;
    }

    /**
     * Delete a category that nothing references.
     *
     * @throws CannotDeleteException
     */
    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            throw new CannotDeleteException("“{$category->name}” has subcategories. Move or delete them first, or deactivate this category instead.");
        }

        if ($category->products()->exists()) {
            throw new CannotDeleteException("“{$category->name}” has products. Move them to another category, or deactivate this category instead.");
        }

        try {
            $category->delete();
        } catch (QueryException $exception) {
            // A product or subcategory was attached concurrently; the FK RESTRICT caught it.
            throw new CannotDeleteException("“{$category->name}” is in use and cannot be deleted.", previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    /**
     * @throws InvalidCategoryParentException
     */
    private function assertValidParent(?int $categoryId, ?int $parentId): void
    {
        if ($error = CategoryTree::load()->parentError($categoryId, $parentId)) {
            throw InvalidCategoryParentException::because($error);
        }
    }
}
