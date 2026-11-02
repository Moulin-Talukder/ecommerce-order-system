<?php

namespace App\Services\Catalog;

use App\Enums\AuditEvent;
use App\Exceptions\CannotDeleteException;
use App\Models\Brand;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Slug;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Write operations for brands.
 *
 * Every change is audited in its transaction, so a failed audit write rolls
 * the change back. Saves that change no audited field are not recorded.
 */
class BrandService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, slug?: string|null, description?: string|null, is_active?: bool}  $data
     */
    public function create(array $data, ?User $actor = null): Brand
    {
        $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Brand::class, $data['name']);

        return DB::transaction(function () use ($data, $actor): Brand {
            $brand = Brand::create($data);
            $this->audit->record(AuditEvent::BrandCreated, $actor, $brand, new: $this->audit->snapshot($brand));

            return $brand;
        });
    }

    /**
     * @param  array{name: string, slug?: string|null, description?: string|null, is_active?: bool}  $data
     */
    public function update(Brand $brand, array $data, ?User $actor = null): Brand
    {
        $data['slug'] = ($data['slug'] ?? null) ?: Slug::unique(Brand::class, $data['name'], $brand->id);

        return $this->change($brand, $data, AuditEvent::BrandUpdated, $actor);
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

    public function setActive(Brand $brand, bool $active, ?User $actor = null): Brand
    {
        return $this->change($brand, ['is_active' => $active], AuditEvent::BrandStatusChanged, $actor);
    }

    /**
     * Delete a brand that no product references.
     *
     * @throws CannotDeleteException
     */
    public function delete(Brand $brand, ?User $actor = null): void
    {
        if ($brand->products()->exists()) {
            throw new CannotDeleteException("“{$brand->name}” has products. Reassign them, or deactivate this brand instead.");
        }

        try {
            DB::transaction(function () use ($brand, $actor): void {
                $brand->delete();
                $this->audit->record(AuditEvent::BrandDeleted, $actor, $brand, old: $this->audit->snapshot($brand));
            });
        } catch (QueryException $exception) {
            if (! CannotDeleteException::isReferencedRowError($exception)) {
                throw $exception;
            }

            throw new CannotDeleteException("“{$brand->name}” is in use and cannot be deleted.", previous: $exception);
        }
    }

    /**
     * Update the locked row and audit what actually changed.
     *
     * @param  array<string, mixed>  $data
     */
    private function change(Brand $brand, array $data, AuditEvent $event, ?User $actor): Brand
    {
        return DB::transaction(function () use ($brand, $data, $event, $actor): Brand {
            $locked = Brand::query()->lockForUpdate()->findOrFail($brand->id);
            $before = $this->audit->snapshot($locked);

            $locked->update($data);
            $this->audit->recordChanges($event, $actor, $locked, $before);

            $brand->setRawAttributes($locked->getAttributes(), true);

            return $brand;
        });
    }
}
