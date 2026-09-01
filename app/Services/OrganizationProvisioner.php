<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;

/**
 * Gives a brand-new business the structure it cannot function without: one main
 * branch, one till in it, and the starting set of roles (#47, #49, #51).
 *
 * It exists so that "what a new tenant starts with" is answered in exactly one
 * place — the operator's create-business form, the seeder and the tests all call
 * this, so a tenant created by one route can never be shaped differently from a
 * tenant created by another.
 *
 * Idempotent: every step checks before it writes, so re-running it on an
 * existing business is a no-op rather than a duplicate.
 */
class OrganizationProvisioner
{
    public function __construct(
        protected BranchService $branches,
        protected PosCounterService $counters,
        protected RoleService $roles,
        protected CatalogService $catalog,
        protected ExpenseService $expenses,
    ) {}

    public function provision(Business $business): void
    {
        $branch = $this->branches->ensureMainBranch($business);

        $this->counters->ensureDefaultCounter($business, $branch);
        $this->roles->seedSystemRoles($business);

        // One base unit (Piece), so the first product can be added without
        // stopping to invent a unit of measure first (#26, #195).
        $this->catalog->seedDefaults($business);

        // A handful of expense headings, for the same reason: the first expense
        // form should not open onto an empty dropdown (#43).
        $this->expenses->seedDefaults($business);

        // Park the owner in the main branch. It changes nothing about what they
        // may see — an owner reaches every branch — but it gives their sales and
        // sessions a sensible default from Phase 7 onward.
        User::query()
            ->forBusiness($business->id)
            ->where('is_business_owner', true)
            ->whereNull('branch_id')
            ->update(['branch_id' => $branch->id]);
    }
}
