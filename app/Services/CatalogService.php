<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Unit;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\Slug;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * The lists products are filed under: categories, brands and units (#26).
 *
 * Small, deliberately boring CRUD — but three rules run through all of it:
 *
 *   QUOTAS  — categories and brands are metered (#8, #79). Units are not: the
 *             registry has no unit quota, and inventing one would punish shops
 *             for describing their goods accurately.
 *   ARCHIVE — anything referenced by a product is deactivated, never deleted
 *             (#104). The FK is `restrictOnDelete` as the last line of defence.
 *   TENANT  — every lookup goes through a tenant-scoped model, so an id from
 *             another business simply is not found.
 */
class CatalogService
{
    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    /**
     * A new business starts with one base unit, so its first product can be
     * added without stopping to invent a unit of measure first (#195).
     * Idempotent, like the rest of provisioning.
     */
    public function seedDefaults(Business $business): void
    {
        $this->tenant->runFor($business, function () use ($business): void {
            if (Unit::query()->exists()) {
                return;
            }

            $unit = new Unit([
                'name' => 'Piece',
                'short_name' => 'pc',
                'base_unit_id' => null,
                'conversion_factor' => 1,
                'allows_decimals' => false,
                'is_active' => true,
            ]);
            $unit->business_id = $business->id;
            $unit->save();
        });
    }

    // ------------------------------------------------------------ categories

    /** @param  array{name: string, parent_id?: int|null, description?: string|null, is_active?: bool, sort_order?: int}  $data */
    public function createCategory(array $data): Category
    {
        $this->limits->assertCanCreate(LimitRegistry::CATEGORIES);

        $category = new Category([
            'parent_id' => $this->resolveParentId($data['parent_id'] ?? null),
            'name' => $data['name'],
            'slug' => $this->uniqueSlug(Category::class, $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
        $category->save();

        $this->limits->flush();
        $this->audit->log('category.created', $category, "Category \"{$category->name}\" created.");

        return $category;
    }

    /** @param  array<string, mixed>  $data */
    public function updateCategory(Category $category, array $data): Category
    {
        $category->fill([
            'parent_id' => $this->resolveParentId($data['parent_id'] ?? null, $category),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
        ]);

        if (array_key_exists('is_active', $data)) {
            $category->is_active = (bool) $data['is_active'];
        }

        if ($category->isDirty('name')) {
            $category->slug = $this->uniqueSlug(Category::class, $data['name'], $category->id);
        }

        $category->save();

        $this->audit->log('category.updated', $category, "Category \"{$category->name}\" updated.");

        return $category;
    }

    public function deleteCategory(Category $category): bool
    {
        if (! $category->canBeDeleted()) {
            return false;
        }

        $name = $category->name;
        $category->delete();

        $this->limits->flush();
        $this->audit->log('category.deleted', $category, "Category \"{$name}\" deleted.");

        return true;
    }

    // ---------------------------------------------------------------- brands

    /** @param  array{name: string, description?: string|null, is_active?: bool}  $data */
    public function createBrand(array $data): Brand
    {
        $this->limits->assertCanCreate(LimitRegistry::BRANDS);

        $brand = new Brand([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug(Brand::class, $data['name']),
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $brand->save();

        $this->limits->flush();
        $this->audit->log('brand.created', $brand, "Brand \"{$brand->name}\" created.");

        return $brand;
    }

    /** @param  array<string, mixed>  $data */
    public function updateBrand(Brand $brand, array $data): Brand
    {
        $brand->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if (array_key_exists('is_active', $data)) {
            $brand->is_active = (bool) $data['is_active'];
        }

        if ($brand->isDirty('name')) {
            $brand->slug = $this->uniqueSlug(Brand::class, $data['name'], $brand->id);
        }

        $brand->save();

        $this->audit->log('brand.updated', $brand, "Brand \"{$brand->name}\" updated.");

        return $brand;
    }

    public function deleteBrand(Brand $brand): bool
    {
        if (! $brand->canBeDeleted()) {
            return false;
        }

        $name = $brand->name;
        $brand->delete();

        $this->limits->flush();
        $this->audit->log('brand.deleted', $brand, "Brand \"{$name}\" deleted.");

        return true;
    }

    // ----------------------------------------------------------------- units

    /**
     * @param  array{name: string, short_name: string, base_unit_id?: int|null, conversion_factor?: float|null, allows_decimals?: bool, is_active?: bool}  $data
     *
     * @throws FeatureUnavailableException when a derived unit needs multi-unit
     */
    public function createUnit(array $data): Unit
    {
        $baseUnitId = $this->resolveBaseUnitId($data['base_unit_id'] ?? null);

        $unit = new Unit([
            'name' => $data['name'],
            'short_name' => $data['short_name'],
            'base_unit_id' => $baseUnitId,
            'conversion_factor' => $baseUnitId === null ? 1 : $this->positiveFactor($data['conversion_factor'] ?? null),
            'allows_decimals' => $data['allows_decimals'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $unit->save();

        $this->audit->log('unit.created', $unit, "Unit \"{$unit->label()}\" created.");

        return $unit;
    }

    /** @param  array<string, mixed>  $data */
    public function updateUnit(Unit $unit, array $data): Unit
    {
        $baseUnitId = $this->resolveBaseUnitId($data['base_unit_id'] ?? null, $unit);

        $unit->fill([
            'name' => $data['name'],
            'short_name' => $data['short_name'],
            'base_unit_id' => $baseUnitId,
            'conversion_factor' => $baseUnitId === null ? 1 : $this->positiveFactor($data['conversion_factor'] ?? null),
        ]);

        if (array_key_exists('allows_decimals', $data)) {
            $unit->allows_decimals = (bool) $data['allows_decimals'];
        }

        if (array_key_exists('is_active', $data)) {
            $unit->is_active = (bool) $data['is_active'];
        }

        $unit->save();

        $this->audit->log('unit.updated', $unit, "Unit \"{$unit->label()}\" updated.");

        return $unit;
    }

    public function deleteUnit(Unit $unit): bool
    {
        if (! $unit->canBeDeleted()) {
            return false;
        }

        $label = $unit->label();
        $unit->delete();

        $this->audit->log('unit.deleted', $unit, "Unit \"{$label}\" deleted.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * A category's parent must exist in this tenant, must not be the category
     * itself, and must not be one of its own descendants — otherwise the tree
     * becomes a ring and every recursive read hangs.
     */
    protected function resolveParentId(mixed $parentId, ?Category $category = null): ?int
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = Category::find((int) $parentId);

        abort_if($parent === null, 422, 'That parent category does not exist in this business.');

        if ($category !== null) {
            abort_if($parent->id === $category->id, 422, 'A category cannot be its own parent.');
            abort_if($this->isDescendantOf($parent, $category), 422, 'A category cannot sit under one of its own subcategories.');
        }

        return $parent->id;
    }

    protected function isDescendantOf(Category $candidate, Category $ancestor): bool
    {
        $current = $candidate;
        // Bounded walk: a corrupted chain must not spin forever.
        $hops = 0;

        while ($current->parent_id !== null && $hops++ < 50) {
            if ($current->parent_id === $ancestor->id) {
                return true;
            }

            $current = Category::find($current->parent_id);

            if ($current === null) {
                return false;
            }
        }

        return false;
    }

    /**
     * A derived unit is the multi-unit feature (#158, #125): without it, every
     * unit a shop creates is a base unit and stock stays in one measure.
     */
    protected function resolveBaseUnitId(mixed $baseUnitId, ?Unit $unit = null): ?int
    {
        if ($baseUnitId === null || $baseUnitId === '') {
            return null;
        }

        if (! $this->features->enabled(FeatureRegistry::CATALOG_MULTI_UNIT)) {
            throw new FeatureUnavailableException(
                FeatureRegistry::CATALOG_MULTI_UNIT,
                'Multiple units',
            );
        }

        $base = Unit::find((int) $baseUnitId);

        abort_if($base === null, 422, 'That base unit does not exist in this business.');

        if ($unit !== null) {
            abort_if($base->id === $unit->id, 422, 'A unit cannot convert to itself.');
        }

        // One level only: Dozen → Piece is a conversion, Dozen → Box → Piece is
        // a chain nothing in the app is ready to walk yet.
        abort_if($base->base_unit_id !== null, 422, 'Choose a base unit — that one already converts to another unit.');

        return $base->id;
    }

    protected function positiveFactor(mixed $factor): float
    {
        $value = (float) ($factor ?? 0);

        abort_if($value <= 0, 422, 'The conversion factor must be greater than zero.');

        return $value;
    }

    /**
     * Categories and brands both slug into a 140-character column.
     *
     * @param  class-string<Model>  $model
     */
    protected function uniqueSlug(string $model, string $name, ?int $ignoreId = null): string
    {
        $base = Slug::base($name, 140, 'item');
        $slug = $base;
        $i = 2;

        while ($model::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
