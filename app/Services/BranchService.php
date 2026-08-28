<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\LimitExceededException;
use App\Models\Branch;
use App\Models\Business;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Branch lifecycle (#47).
 *
 * Two gates sit in front of creating one, and they answer different questions:
 *   FEATURE — "does this plan do multi-branch at all?" Only asked from the
 *             second branch onwards, because every business has a first one.
 *   LIMIT   — "how many does this plan allow?" (#79)
 *
 * A branch is never hard-deleted once anything points at it (#104): it is
 * deactivated, which keeps its sales and stock history readable while stopping
 * new work from landing there.
 */
class BranchService
{
    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    /**
     * Every business has a main branch from the moment it exists, so a
     * single-shop tenant never has to think about branches. Idempotent.
     */
    public function ensureMainBranch(Business $business): Branch
    {
        return $this->tenant->runFor($business, function () use ($business): Branch {
            $existing = Branch::where('is_main', true)->first() ?? Branch::orderBy('id')->first();

            if ($existing !== null) {
                return $existing;
            }

            $branch = new Branch([
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'phone' => $business->phone,
                'email' => $business->email,
                'address' => $business->address,
                'is_active' => true,
            ]);
            $branch->business_id = $business->id;
            $branch->is_main = true;
            $branch->save();

            return $branch;
        });
    }

    /**
     * @param  array{name: string, code?: string|null, phone?: string|null, email?: string|null, address?: string|null, city?: string|null, is_active?: bool}  $data
     *
     * @throws FeatureUnavailableException|LimitExceededException
     */
    public function create(array $data): Branch
    {
        // Second and subsequent branches only (#125).
        if (Branch::count() >= 1 && ! $this->features->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH)) {
            throw new FeatureUnavailableException(
                FeatureRegistry::BRANCHES_MULTI_BRANCH,
                'Multiple branches',
            );
        }

        $this->limits->assertCanCreate(LimitRegistry::BRANCHES);

        return DB::transaction(function () use ($data): Branch {
            $branch = new Branch([
                'name' => $data['name'],
                'code' => $this->uniqueCode($data['code'] ?? null, $data['name']),
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // The first branch of a business is automatically the main one.
            $branch->is_main = ! Branch::where('is_main', true)->exists();
            $branch->save();

            $this->limits->flush();

            $this->audit->log('branch.created', $branch, "Branch \"{$branch->name}\" created.");

            return $branch;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Branch $branch, array $data): Branch
    {
        $branch->fill([
            'name' => $data['name'],
            'code' => $this->uniqueCode($data['code'] ?? $branch->code, $data['name'], $branch->id),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
        ]);

        // The main branch cannot be switched off — it is the fallback everything
        // else falls back to.
        if (array_key_exists('is_active', $data) && ! $branch->is_main) {
            $branch->is_active = (bool) $data['is_active'];
        }

        $branch->save();

        $this->audit->log('branch.updated', $branch, "Branch \"{$branch->name}\" updated.");

        return $branch;
    }

    /** Promote a branch to main; the previous main steps down. */
    public function makeMain(Branch $branch): Branch
    {
        if ($branch->is_main) {
            return $branch;
        }

        DB::transaction(function () use ($branch): void {
            Branch::where('is_main', true)->update(['is_main' => false]);

            $branch->is_main = true;
            // A branch that runs the business cannot be closed at the same time.
            $branch->is_active = true;
            $branch->save();
        });

        $this->audit->log('branch.made_main', $branch, "Branch \"{$branch->name}\" is now the main branch.");

        return $branch;
    }

    public function setActive(Branch $branch, bool $active): Branch
    {
        if ($branch->is_main && ! $active) {
            return $branch;
        }

        $branch->is_active = $active;
        $branch->save();

        $this->audit->log(
            $active ? 'branch.activated' : 'branch.deactivated',
            $branch,
            "Branch \"{$branch->name}\" ".($active ? 'reopened' : 'closed').'.',
        );

        return $branch;
    }

    /**
     * Only an empty, non-main branch can actually be removed. Anything with
     * staff or tills is archived instead (#104) — the caller shows why.
     */
    public function delete(Branch $branch): bool
    {
        if (! $branch->canBeDeleted()) {
            return false;
        }

        $name = $branch->name;
        $branch->delete();

        $this->limits->flush();
        $this->audit->log('branch.deleted', $branch, "Branch \"{$name}\" deleted.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * Codes are short, unique per business, and survive archiving — see the
     * migration. Generated from the name when the operator leaves it blank.
     */
    protected function uniqueCode(?string $code, string $name, ?int $ignoreId = null): string
    {
        $base = Str::upper(Str::slug($code ?: Str::limit($name, 8, ''), ''));
        $base = $base !== '' ? substr($base, 0, 16) : 'BR';

        $candidate = $base;
        $i = 2;

        while (Branch::withTrashed()
            ->where('code', $candidate)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $candidate = $base.$i++;
        }

        return $candidate;
    }
}
