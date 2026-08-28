<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Role;
use App\Support\PermissionRegistry as P;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating and editing roles, and seeding the presets a new business starts with
 * (#51).
 *
 * The presets are STARTING POINTS, not policy: they are copied into the tenant
 * as ordinary editable rows the moment the business is created, and from then on
 * they are the owner's to change. Nothing in the app ever asks "is this user a
 * cashier" — it asks whether they hold a permission.
 */
class RoleService
{
    public function __construct(
        protected TenantContext $tenant,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /**
     * The three roles a new business starts with.
     *
     * Chosen to cover the shape of an actual shop: someone who runs the place,
     * someone who stands at the till, someone who handles stock. Sensitive
     * permissions (#52) are given only to the Manager, and even there not the
     * ones that change who can do what — that stays with the owner.
     *
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public static function presets(): array
    {
        return [
            'manager' => [
                'name' => 'Manager',
                'description' => 'Runs the shop day to day: sales, stock, purchases, staff and reports.',
                'permissions' => [
                    P::DASHBOARD_VIEW,
                    P::POS_OPERATE, P::POS_DISCOUNT, P::POS_OPEN_REGISTER, P::POS_CLOSE_REGISTER,
                    P::SALES_VIEW, P::SALES_VIEW_ALL, P::SALES_CREATE, P::SALES_UPDATE,
                    P::SALES_VOID, P::SALES_RETURN, P::SALES_PAYMENT_RECORD, P::SALES_PAYMENT_REFUND,
                    P::PRODUCTS_VIEW, P::PRODUCTS_CREATE, P::PRODUCTS_UPDATE, P::PRODUCTS_DELETE,
                    P::PRODUCTS_VIEW_COST, P::PRODUCTS_IMPORT, P::CATALOG_MANAGE,
                    P::INVENTORY_VIEW, P::INVENTORY_ADJUST, P::INVENTORY_TRANSFER, P::INVENTORY_STOCK_TAKE,
                    P::PURCHASES_VIEW, P::PURCHASES_CREATE, P::PURCHASES_UPDATE, P::PURCHASES_VOID, P::PURCHASES_RETURN,
                    P::SUPPLIERS_VIEW, P::SUPPLIERS_MANAGE, P::SUPPLIERS_LEDGER,
                    P::CUSTOMERS_VIEW, P::CUSTOMERS_MANAGE, P::CUSTOMERS_LEDGER,
                    P::EMPLOYEES_VIEW, P::EMPLOYEES_MANAGE,
                    P::EXPENSES_VIEW, P::EXPENSES_MANAGE,
                    P::REPORTS_VIEW, P::REPORTS_VIEW_PROFIT, P::REPORTS_EXPORT,
                    P::BRANCHES_VIEW, P::POS_COUNTERS_MANAGE,
                ],
            ],
            'cashier' => [
                'name' => 'Cashier',
                'description' => 'Works the till: rings up sales, takes payments, looks up customers.',
                'permissions' => [
                    P::DASHBOARD_VIEW,
                    P::POS_OPERATE, P::POS_OPEN_REGISTER,
                    P::SALES_VIEW, P::SALES_CREATE, P::SALES_PAYMENT_RECORD,
                    P::PRODUCTS_VIEW,
                    P::CUSTOMERS_VIEW, P::CUSTOMERS_MANAGE,
                    P::BRANCHES_VIEW,
                ],
            ],
            'stock-keeper' => [
                'name' => 'Stock Keeper',
                'description' => 'Looks after the catalogue and the stock room, but not the money.',
                'permissions' => [
                    P::DASHBOARD_VIEW,
                    P::PRODUCTS_VIEW, P::PRODUCTS_CREATE, P::PRODUCTS_UPDATE, P::CATALOG_MANAGE,
                    P::INVENTORY_VIEW, P::INVENTORY_ADJUST, P::INVENTORY_TRANSFER, P::INVENTORY_STOCK_TAKE,
                    P::PURCHASES_VIEW, P::PURCHASES_CREATE,
                    P::SUPPLIERS_VIEW, P::SUPPLIERS_MANAGE,
                    P::BRANCHES_VIEW,
                ],
            ],
        ];
    }

    /**
     * Give a brand-new business its starting roles. Idempotent — a business that
     * already has roles is left alone, so this is safe to call from both the
     * operator's create-business flow and a seeder.
     *
     * @return list<Role>
     */
    public function seedSystemRoles(Business $business): array
    {
        return $this->tenant->runFor($business, function () use ($business): array {
            $created = [];

            foreach (self::presets() as $slug => $preset) {
                $existing = Role::where('slug', $slug)->first();

                if ($existing !== null) {
                    $created[] = $existing;

                    continue;
                }

                $role = new Role([
                    'name' => $preset['name'],
                    'description' => $preset['description'],
                    'slug' => $slug,
                ]);
                $role->business_id = $business->id;
                $role->is_system = true;
                $role->save();

                $this->writePermissions($role, $preset['permissions']);

                $created[] = $role;
            }

            return $created;
        });
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     * @param  list<string>  $permissions
     */
    public function create(array $data, array $permissions): Role
    {
        return DB::transaction(function () use ($data, $permissions): Role {
            $role = new Role([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'slug' => $this->uniqueSlug($data['name']),
            ]);
            $role->save();

            $this->writePermissions($role, $this->grantable($permissions));

            $this->audit->log(
                'role.created',
                $role,
                "Role \"{$role->name}\" created.",
                ['permissions' => $role->fresh()->permissionCodes()],
            );

            $this->permissions->flush();

            return $role;
        });
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     * @param  list<string>  $permissions
     */
    public function update(Role $role, array $data, array $permissions): Role
    {
        return DB::transaction(function () use ($role, $data, $permissions): Role {
            $before = $role->permissionCodes();

            $role->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            // System roles keep their slug: it is what seedSystemRoles() matches
            // on, and a renamed preset must not be re-created on the next call.
            if (! $role->is_system && $role->isDirty('name')) {
                $role->slug = $this->uniqueSlug($data['name'], $role->id);
            }

            $role->save();

            /*
             | Dormant permissions are PRESERVED. A tenant that downgrades loses
             | the feature behind a permission, so it stops being grantable — but
             | wiping it here would mean an upgrade silently came back with the
             | box unticked. The editor never shows them; the sync keeps them.
             */
            $keep = array_values(array_intersect(
                $before,
                $this->permissions->dormantCodesFor($role),
            ));

            $this->writePermissions($role, array_unique(array_merge($this->grantable($permissions), $keep)));

            $after = $role->fresh()->permissionCodes();

            if ($before !== $after) {
                $this->audit->log(
                    'role.permissions_changed',
                    $role,
                    "Permissions changed for role \"{$role->name}\".",
                    [
                        'added' => array_values(array_diff($after, $before)),
                        'removed' => array_values(array_diff($before, $after)),
                    ],
                );
            }

            $this->permissions->flush();

            return $role;
        });
    }

    /**
     * Roles in use are never deleted (#104) — the people holding them would be
     * left with no permissions at all, silently.
     */
    public function delete(Role $role): bool
    {
        if (! $role->canBeDeleted()) {
            return false;
        }

        $name = $role->name;
        $role->permissions()->delete();
        $role->delete();

        $this->audit->log('role.deleted', $role, "Role \"{$name}\" deleted.");
        $this->permissions->flush();

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * Only codes the plan currently supports may be granted (#125). Unknown
     * codes are dropped rather than stored — the registry is the vocabulary.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    protected function grantable(array $codes): array
    {
        $allowed = $this->permissions->grantableCodes();

        return array_values(array_intersect(array_unique($codes), $allowed));
    }

    /** @param  list<string>  $codes */
    protected function writePermissions(Role $role, array $codes): void
    {
        $role->permissions()->delete();

        if ($codes === []) {
            $role->unsetRelation('permissions');

            return;
        }

        $role->permissions()->insert(array_map(
            fn (string $code) => ['role_id' => $role->id, 'permission' => $code],
            array_values(array_unique($codes)),
        ));

        $role->unsetRelation('permissions');
    }

    protected function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 2;

        while (Role::where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->withTrashed()
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
