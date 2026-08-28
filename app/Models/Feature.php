<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A gate-able capability (#128). Operator-owned catalog row; the canonical list
 * of codes lives in {@see \App\Support\FeatureRegistry}, which seeds this table.
 */
class Feature extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'group',
        'default_enabled',
        'sort_order',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_enabled' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_feature')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(BusinessFeatureOverride::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('group')->orderBy('sort_order')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
