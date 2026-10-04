<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\CannotDeleteException;
use App\Models\Brand;
use App\Support\Slug;
use Illuminate\Database\QueryException;

/**
 * Write operations for brands.
 */
class BrandService
{
    /**
     * @param  array{name: string, slug?: string|null, description?: string|null, is_active?: bool}  $data
     */
    public function create(array $data): Brand
    {
        $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Brand::class, $data['name']);

        return Brand::create($data);
    }

    /**
     * @param  array{name: string, slug?: string|null, description?: string|null, is_active?: bool}  $data
     */
    public function update(Brand $brand, array $data): Brand
    {
        $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Brand::class, $data['name'], $brand->id);

        $brand->update($data);

        return $brand;
    }

    /**
     * Brands a product may be assigned to: active ones, plus $alwaysInclude
     * (a product's current brand) even if it is inactive.
     *
     * @return array<int, string> id => name
     */
    public function productBrandOptions(?int $alwaysInclude = null): array
    {
        return Brand::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($alwaysInclude, fn ($query) => $query->orWhereKey($alwaysInclude)))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn (Brand $brand) => [$brand->id => $brand->is_active ? $brand->name : $brand->name.' (inactive)'])
            ->all();
    }

    public function setActive(Brand $brand, bool $active): Brand
    {
        $brand->update(['is_active' => $active]);

        return $brand;
    }

    /**
     * Delete a brand that no product references.
     *
     * @throws CannotDeleteException
     */
    public function delete(Brand $brand): void
    {
        if ($brand->products()->exists()) {
            throw new CannotDeleteException("“{$brand->name}” has products. Reassign them, or deactivate this brand instead.");
        }

        try {
            $brand->delete();
        } catch (QueryException $exception) {
            throw new CannotDeleteException("“{$brand->name}” is in use and cannot be deleted.", previous: $exception);
        }
    }
}
