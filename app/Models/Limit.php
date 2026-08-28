<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A countable quota (#8, #129). Operator-owned catalog row; codes are declared
 * in {@see \App\Support\LimitRegistry}, which seeds this table.
 *
 * NULL = unlimited, throughout this layer. `default_unlimited` disambiguates
 * "NULL because unlimited" from "NULL because nobody configured it" — the
 * latter resolves to 0 so enforcement stays fail-closed.
 */
class Limit extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'unit',
        'group',
        'default_value',
        'default_unlimited',
        'is_monthly',
        'sort_order',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_value' => 'integer',
            'default_unlimited' => 'boolean',
            'is_monthly' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_limit')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(BusinessLimitOverride::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('group')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The registry-level fallback, applied when neither the business nor the plan
     * says anything. NULL means unlimited; 0 means "not allowed".
     */
    public function defaultValue(): ?int
    {
        if ($this->default_unlimited) {
            return null;
        }

        return (int) ($this->default_value ?? 0);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
