<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How a shop files what it spends (#43). Tenant-scoped and invented by the
 * shop, not by us — see the migration for why.
 */
class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
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

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // --------------------------------------------------------------- helpers

    /**
     * A category with expenses filed under it is archived, not deleted (#104) —
     * last quarter's P&L still reads those rows and needs the heading.
     */
    public function isInUse(): bool
    {
        return $this->expenses()->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isInUse();
    }
}
