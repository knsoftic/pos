<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\PosCounterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A till inside a branch (#49). Tenant-scoped, and additionally filtered by the
 * branches the current user may reach — a cashier at the High Street shop has no
 * business listing the tills at the depot (#48).
 */
class PosCounter extends Model
{
    /** @use HasFactory<PosCounterFactory> */
    use BelongsToBranch, BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /** `branch_id` is fillable but always re-checked against the tenant. */
    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------- relations

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param  list<int>  $branchIds */
    public function scopeInBranches(Builder $query, array $branchIds): Builder
    {
        return $query->whereIn('branch_id', $branchIds);
    }

    // --------------------------------------------------------------- helpers

    public function isInUse(): bool
    {
        return $this->users()->withoutGlobalScope(SoftDeletingScope::class)->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isInUse();
    }
}
