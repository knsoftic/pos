<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to any tenant model that lives in a branch — POS counters now, and from
 * Phase 4 sales, stock and cash sessions (#48, #138).
 *
 * Always used TOGETHER with {@see BelongsToTenant}, never instead of it. The two
 * scopes stack: business first, then branch.
 *
 * Deliberately different from BelongsToTenant in one way: this trait does NOT
 * force `branch_id` on create. A tenant is a fact about the logged-in user, but
 * the branch a record belongs to is a business decision — an owner transferring
 * stock or creating a till in another branch is normal. Writes are validated by
 * the service layer instead (`BranchContext::allows()`), which can tell an owner
 * from a cashier; a blanket stamp could not.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /** Override with a BRANCH_COLUMN constant if the column differs. */
    public function getBranchColumn(): string
    {
        return defined(static::class.'::BRANCH_COLUMN') ? static::BRANCH_COLUMN : 'branch_id';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, $this->getBranchColumn());
    }

    /** Query every branch in the tenant, ignoring the user's branch limits. */
    public function scopeAllBranches(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BranchScope::class);
    }
}
