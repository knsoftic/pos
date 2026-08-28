<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use App\Support\BranchContext;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A shop location (#47). Tenant-scoped.
 *
 * Note what is NOT here: no branch-level scoping of the branch list itself. A
 * cashier tied to one branch still needs to know it exists, and the list is not
 * sensitive. What branch membership gates is the DATA inside a branch — sales,
 * stock, cash sessions — which {@see BelongsToBranch}
 * handles from Phase 4 onward (#48, #138).
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'code',
        'phone',
        'email',
        'address',
        'city',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------- relations

    public function counters(): HasMany
    {
        return $this->hasMany(PosCounter::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_main')->orderBy('name');
    }

    /**
     * Only the branches the current user may reach (#48). Opt-in rather than a
     * global scope: the branch LIST is not sensitive — a cashier may see that
     * the company has three shops — but anything that assigns work to a branch
     * must offer only the ones that person can actually act in, so those callers
     * ask for this explicitly.
     */
    public function scopeAccessible(Builder $query): Builder
    {
        $ids = app(BranchContext::class)->branchIds();

        return $ids === null ? $query : $query->whereIn($this->getTable().'.id', $ids);
    }

    // --------------------------------------------------------------- helpers

    /**
     * The main branch may not be archived and no branch with staff or tills may
     * be deleted (#104) — it is deactivated instead, keeping its history intact.
     */
    public function isInUse(): bool
    {
        return $this->users()->withoutGlobalScope(SoftDeletingScope::class)->exists()
            || $this->counters()->withoutGlobalScope(SoftDeletingScope::class)->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->is_main && ! $this->isInUse();
    }
}
