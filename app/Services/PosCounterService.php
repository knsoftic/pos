<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\LimitExceededException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PosCounter;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Till lifecycle (#49). Same two gates as branches — the multi-counter feature
 * from the second till onward, and the plan quota — plus one more that branches
 * do not need: the branch a counter is being put in must be one the acting user
 * may actually reach (#48).
 */
class PosCounterService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branchContext,
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    /** Every branch starts with one till, so the POS is usable immediately. */
    public function ensureDefaultCounter(Business $business, Branch $branch): PosCounter
    {
        return $this->tenant->runFor($business, function () use ($branch): PosCounter {
            $existing = PosCounter::allBranches()->where('branch_id', $branch->id)->orderBy('id')->first();

            if ($existing !== null) {
                return $existing;
            }

            $counter = new PosCounter([
                'branch_id' => $branch->id,
                'name' => 'Counter 1',
                'code' => $this->uniqueCode(null, $branch->code.'-C1'),
                'is_active' => true,
            ]);
            $counter->business_id = $branch->business_id;
            $counter->save();

            return $counter;
        });
    }

    /**
     * @param  array{branch_id: int, name: string, code?: string|null, is_active?: bool}  $data
     *
     * @throws FeatureUnavailableException|LimitExceededException
     */
    public function create(array $data): PosCounter
    {
        $branch = $this->resolveBranch((int) $data['branch_id']);

        if (PosCounter::allBranches()->count() >= 1 && ! $this->features->enabled(FeatureRegistry::POS_MULTI_COUNTER)) {
            throw new FeatureUnavailableException(
                FeatureRegistry::POS_MULTI_COUNTER,
                'Multiple POS counters',
            );
        }

        $this->limits->assertCanCreate(LimitRegistry::POS_COUNTERS);

        return DB::transaction(function () use ($branch, $data): PosCounter {
            $counter = new PosCounter([
                'branch_id' => $branch->id,
                'name' => $data['name'],
                'code' => $this->uniqueCode($data['code'] ?? null, $data['name']),
                'is_active' => $data['is_active'] ?? true,
            ]);
            $counter->save();

            $this->limits->flush();
            $this->audit->log('pos_counter.created', $counter, "Counter \"{$counter->name}\" created in {$branch->name}.");

            return $counter;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PosCounter $counter, array $data): PosCounter
    {
        if (array_key_exists('branch_id', $data) && (int) $data['branch_id'] !== (int) $counter->branch_id) {
            $counter->branch_id = $this->resolveBranch((int) $data['branch_id'])->id;
        }

        $counter->fill([
            'name' => $data['name'],
            'code' => $this->uniqueCode($data['code'] ?? $counter->code, $data['name'], $counter->id),
        ]);

        if (array_key_exists('is_active', $data)) {
            $counter->is_active = (bool) $data['is_active'];
        }

        $counter->save();

        $this->audit->log('pos_counter.updated', $counter, "Counter \"{$counter->name}\" updated.");

        return $counter;
    }

    public function setActive(PosCounter $counter, bool $active): PosCounter
    {
        $counter->is_active = $active;
        $counter->save();

        $this->audit->log(
            $active ? 'pos_counter.activated' : 'pos_counter.deactivated',
            $counter,
            "Counter \"{$counter->name}\" ".($active ? 'enabled' : 'disabled').'.',
        );

        return $counter;
    }

    /** A till someone is assigned to is disabled, not deleted (#104). */
    public function delete(PosCounter $counter): bool
    {
        if (! $counter->canBeDeleted()) {
            return false;
        }

        $name = $counter->name;
        $counter->delete();

        $this->limits->flush();
        $this->audit->log('pos_counter.deleted', $counter, "Counter \"{$name}\" deleted.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * The branch must exist in THIS tenant (the global scope guarantees that)
     * and be one the acting user may reach (#48) — a manager cannot install a
     * till in a branch they have no business in.
     */
    protected function resolveBranch(int $branchId): Branch
    {
        $branch = Branch::find($branchId);

        abort_if($branch === null, 404, 'Branch not found.');
        abort_unless($this->branchContext->allows($branch->id), 403, 'That branch is outside your access.');

        return $branch;
    }

    protected function uniqueCode(?string $code, string $name, ?int $ignoreId = null): string
    {
        $base = Str::upper(Str::slug($code ?: Str::limit($name, 8, ''), ''));
        $base = $base !== '' ? substr($base, 0, 16) : 'POS';

        $candidate = $base;
        $i = 2;

        while (PosCounter::allBranches()
            ->withTrashed()
            ->where('code', $candidate)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $candidate = $base.$i++;
        }

        return $candidate;
    }
}
