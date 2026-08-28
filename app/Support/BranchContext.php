<?php

namespace App\Support;

use App\Http\Middleware\SetBusinessTenant;
use App\Models\User;

/**
 * Holds which branches the current user may reach, for the lifetime of a request
 * (bound as a singleton, exactly like {@see TenantContext}) — #48, #138.
 *
 * THE RULE, in one place:
 *   Owner                      → every branch, no filter.
 *   Anyone with a branch       → only that branch.
 *   Anyone without a branch    → NOTHING. Fail closed: an employee who has not
 *                                been given a branch cannot see branch data at
 *                                all, rather than silently seeing all of it.
 *
 * Set by {@see SetBusinessTenant} from the authenticated
 * user, never from request input — same reason as tenancy. The tenant scope
 * still runs underneath: this narrows within a business, it can never widen
 * across one.
 *
 * Nothing in Phase 3 stores transactional data yet except POS counters, so this
 * is deliberately built now and applied to counters, ready for Phase 4+ models
 * to pick up with one trait.
 */
class BranchContext
{
    /** @var list<int>|null null = unrestricted (owner / system code) */
    protected ?array $branchIds = null;

    protected bool $restricted = false;

    /** Resolve and remember the rule for one user. */
    public function forUser(User $user): void
    {
        if ($user->isOwner()) {
            $this->unrestrict();

            return;
        }

        $this->restrictTo($user->branch_id === null ? [] : [(int) $user->branch_id]);
    }

    /** @param  list<int>  $branchIds */
    public function restrictTo(array $branchIds): void
    {
        $this->branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        $this->restricted = true;
    }

    public function unrestrict(): void
    {
        $this->branchIds = null;
        $this->restricted = false;
    }

    public function forget(): void
    {
        $this->unrestrict();
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    /** @return list<int>|null  null when unrestricted. */
    public function branchIds(): ?array
    {
        return $this->restricted ? ($this->branchIds ?? []) : null;
    }

    public function allows(?int $branchId): bool
    {
        if (! $this->restricted) {
            return true;
        }

        if ($branchId === null) {
            return false;
        }

        return in_array((int) $branchId, $this->branchIds ?? [], true);
    }

    /**
     * Run a callback with the branch filter lifted — for owner-level screens and
     * system code that must legitimately see every branch. Explicit by design,
     * and always restores the previous state.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runUnrestricted(callable $callback)
    {
        $previousIds = $this->branchIds;
        $previousFlag = $this->restricted;

        $this->unrestrict();

        try {
            return $callback();
        } finally {
            $this->branchIds = $previousIds;
            $this->restricted = $previousFlag;
        }
    }
}
