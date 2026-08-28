<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\LimitExceededException;
use App\Models\Branch;
use App\Models\PosCounter;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Staff accounts (#50, #138, #141).
 *
 * Everything that decides what a person may do — role, branch, till, discount
 * cap, active flag — is guarded on the model and can only be set here, and only
 * after each value has been checked against the current tenant. That is what
 * stops a crafted form post from attaching an employee to another business's
 * branch or handing themselves a role from a different tenant.
 *
 * OWNERSHIP IS NOT TRANSFERABLE HERE. `is_business_owner` is never written by
 * this service: the owner account is created with the business (by the operator)
 * and stays the owner. Making ownership something a form could grant would make
 * every other guard in this class negotiable.
 */
class EmployeeService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branchContext,
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, password: string, role_id?: int|null, branch_id?: int|null, pos_counter_id?: int|null, max_discount_percent?: numeric-string|float|null, is_active?: bool}  $data
     *
     * @throws FeatureUnavailableException|LimitExceededException
     */
    public function create(array $data): User
    {
        // The owner alone is one user; anyone beyond that is a team (#125).
        if (User::count() >= 1 && ! $this->features->enabled(FeatureRegistry::TEAM_MULTI_USER)) {
            throw new FeatureUnavailableException(
                FeatureRegistry::TEAM_MULTI_USER,
                'Multiple users',
            );
        }

        $this->limits->assertCanCreate(LimitRegistry::EMPLOYEES);

        return DB::transaction(function () use ($data): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            // Guarded columns, set only after validation against this tenant.
            $this->applyAssignment($user, $data);
            $user->is_active = $data['is_active'] ?? true;
            $user->is_business_owner = false;

            $user->save();

            $this->limits->flush();

            $this->audit->log(
                'employee.created',
                $user,
                "Employee \"{$user->name}\" added.",
                [
                    'role' => $user->role?->name,
                    'branch_id' => $user->branch_id,
                ],
            );

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        $actor ??= auth('web')->user();

        return DB::transaction(function () use ($user, $data, $actor): User {
            $before = [
                'role_id' => $user->role_id,
                'branch_id' => $user->branch_id,
                'pos_counter_id' => $user->pos_counter_id,
                'max_discount_percent' => $user->max_discount_percent,
                'is_active' => $user->is_active,
            ];

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            /*
             | The owner's own assignment is not editable from the staff screen:
             | an owner already reaches everything, and letting this form write a
             | role onto them is how a business locks itself out of itself.
             */
            if (! $user->isOwner()) {
                $this->applyAssignment($user, $data);

                // Nobody may switch themselves off — that is a support ticket
                // waiting to happen, and it is always a mistake.
                if (array_key_exists('is_active', $data) && $actor?->id !== $user->id) {
                    $user->is_active = (bool) $data['is_active'];
                }
            }

            $user->save();

            $after = [
                'role_id' => $user->role_id,
                'branch_id' => $user->branch_id,
                'pos_counter_id' => $user->pos_counter_id,
                'max_discount_percent' => $user->max_discount_percent,
                'is_active' => $user->is_active,
            ];

            if ($before !== $after) {
                $this->audit->log(
                    'employee.assignment_changed',
                    $user,
                    "Assignment changed for \"{$user->name}\".",
                    ['before' => $before, 'after' => $after],
                );
            }

            $this->permissions->flush($user->id);

            return $user;
        });
    }

    public function setActive(User $user, bool $active, ?User $actor = null): User
    {
        $actor ??= auth('web')->user();

        // Same rule as update(): not yourself, and never the owner.
        if ($user->isOwner() || $actor?->id === $user->id) {
            return $user;
        }

        $user->is_active = $active;
        $user->save();

        $this->audit->log(
            $active ? 'employee.activated' : 'employee.deactivated',
            $user,
            "Employee \"{$user->name}\" ".($active ? 'reactivated' : 'deactivated').'.',
        );

        return $user;
    }

    public function resetPassword(User $user, string $password): User
    {
        $user->password = $password;
        $user->setRememberToken(null);
        $user->save();

        $this->audit->log('employee.password_reset', $user, "Password reset for \"{$user->name}\".");

        return $user;
    }

    /**
     * Soft delete: the row stays so past sales still resolve to a person (#104,
     * #198). The seat is released, so the plan's employee quota frees up.
     */
    public function delete(User $user, ?User $actor = null): bool
    {
        $actor ??= auth('web')->user();

        if ($user->isOwner() || $actor?->id === $user->id) {
            return false;
        }

        $name = $user->name;
        $user->delete();

        $this->limits->flush();
        $this->audit->log('employee.deleted', $user, "Employee \"{$name}\" removed.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * Validate and apply the four guarded assignment columns.
     *
     * Every lookup goes through the tenant-scoped model, so an id belonging to
     * another business simply is not found — the request cannot reach across.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applyAssignment(User $user, array $data): void
    {
        if (array_key_exists('role_id', $data)) {
            $user->role_id = $this->resolveRoleId($data['role_id']);
        }

        if (array_key_exists('branch_id', $data)) {
            $user->branch_id = $this->resolveBranchId($data['branch_id']);
        }

        if (array_key_exists('pos_counter_id', $data)) {
            $user->pos_counter_id = $this->resolveCounterId($data['pos_counter_id'], $user->branch_id);
        }

        if (array_key_exists('max_discount_percent', $data)) {
            $value = $data['max_discount_percent'];

            // '' from an empty form field means "no cap", 0 means "no discounts".
            $user->max_discount_percent = ($value === null || $value === '')
                ? null
                : max(0, min(100, (float) $value));
        }
    }

    protected function resolveRoleId(mixed $roleId): ?int
    {
        if ($roleId === null || $roleId === '') {
            return null;
        }

        $role = Role::find((int) $roleId);

        abort_if($role === null, 422, 'That role does not exist in this business.');

        return $role->id;
    }

    protected function resolveBranchId(mixed $branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        $branch = Branch::find((int) $branchId);

        abort_if($branch === null, 422, 'That branch does not exist in this business.');
        abort_unless($this->branchContext->allows($branch->id), 403, 'That branch is outside your access.');

        return $branch->id;
    }

    protected function resolveCounterId(mixed $counterId, ?int $branchId): ?int
    {
        if ($counterId === null || $counterId === '') {
            return null;
        }

        $counter = PosCounter::allBranches()->find((int) $counterId);

        abort_if($counter === null, 422, 'That counter does not exist in this business.');
        abort_unless($this->branchContext->allows($counter->branch_id), 403, 'That counter is outside your access.');

        // A till in one branch and a person in another is a contradiction, and
        // it is exactly how a cashier would end up selling from the wrong stock.
        abort_if(
            $branchId !== null && (int) $counter->branch_id !== (int) $branchId,
            422,
            'That counter belongs to a different branch.',
        );

        return $counter->id;
    }
}
