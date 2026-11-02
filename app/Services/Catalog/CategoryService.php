<?php

namespace App\Services\Catalog;

use App\Enums\AuditEvent;
use App\Exceptions\CannotDeleteException;
use App\Exceptions\Catalog\InvalidCategoryParentException;
use App\Models\Category;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Slug;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Write operations for categories. All hierarchy rules (max depth, no cycles)
 * are enforced here via {@see CategoryTree}, regardless of the caller.
 *
 * Every change is audited in its transaction, so a failed audit write rolls
 * the change back. Saves that change no audited field are not recorded.
 */
class CategoryService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, slug?: string|null, parent_id?: int|null, description?: string|null, is_active?: bool, sort_order?: int|null, meta_title?: string|null, meta_description?: string|null}  $data
     */
    public function create(array $data, ?User $actor = null): Category
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($data, $actor): Category {
            $this->assertValidParent(null, $data['parent_id']);

            $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Category::class, $data['name']);

            $category = Category::create($data);
            $this->audit->record(AuditEvent::CategoryCreated, $actor, $category, new: $this->audit->snapshot($category));

            return $category;
        });
    }

    /**
     * @param  array{name: string, slug?: string|null, parent_id?: int|null, description?: string|null, is_active?: bool, sort_order?: int|null, meta_title?: string|null, meta_description?: string|null}  $data
     */
    public function update(Category $category, array $data, ?User $actor = null): Category
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($category, $data, $actor): Category {
            $this->assertValidParent($category->id, $data['parent_id']);

            $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Category::class, $data['name'], $category->id);

            return $this->change($category, $data, AuditEvent::CategoryUpdated, $actor);
        });
    }

    /**
     * Deactivating a category does not change its subcategories; the storefront
     * treats a category as visible only when it and all its ancestors are active.
     */
    public function setActive(Category $category, bool $active, ?User $actor = null): Category
    {
        return DB::transaction(fn (): Category => $this->change($category, ['is_active' => $active], AuditEvent::CategoryStatusChanged, $actor));
    }

    /**
     * Delete a category that nothing references.
     *
     * @throws CannotDeleteException
     */
    public function delete(Category $category, ?User $actor = null): void
    {
        if ($category->children()->exists()) {
            throw new CannotDeleteException("“{$category->name}” has subcategories. Move or delete them first, or deactivate this category instead.");
        }

        if ($category->products()->exists()) {
            throw new CannotDeleteException("“{$category->name}” has products. Move them to another category, or deactivate this category instead.");
        }

        try {
            DB::transaction(function () use ($category, $actor): void {
                $category->delete();
                $this->audit->record(AuditEvent::CategoryDeleted, $actor, $category, old: $this->audit->snapshot($category));
            });
        } catch (QueryException $exception) {
            if (! CannotDeleteException::isReferencedRowError($exception)) {
                throw $exception;
            }

            // A product or subcategory was attached concurrently; the FK RESTRICT caught it.
            throw new CannotDeleteException("“{$category->name}” is in use and cannot be deleted.", previous: $exception);
        }
    }

    /**
     * Update the locked row and audit what actually changed. Call inside a transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function change(Category $category, array $data, AuditEvent $event, ?User $actor): Category
    {
        $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
        $before = $this->audit->snapshot($locked);

        $locked->update($data);
        $this->audit->recordChanges($event, $actor, $locked, $before);

        $category->setRawAttributes($locked->getAttributes(), true);

        return $category;
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
