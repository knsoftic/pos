<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A product category, optionally nested under another (#26). Tenant-scoped.
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ------------------------------------------------------------- relations

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // --------------------------------------------------------------- helpers

    /** "Drinks → Cold" — for lists where the parent gives the child meaning. */
    public function pathName(): string
    {
        return $this->parent === null
            ? $this->name
            : $this->parent->name.' → '.$this->name;
    }

    /**
     * A category holding products or subcategories is archived, not deleted
     * (#104) — the products would otherwise lose their filing.
     */
    public function isInUse(): bool
    {
        return $this->products()->withoutGlobalScope(SoftDeletingScope::class)->exists()
            || $this->children()->withoutGlobalScope(SoftDeletingScope::class)->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isInUse();
    }
}
