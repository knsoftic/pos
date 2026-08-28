<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\RoleRequest;
use App\Models\Role;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Support\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Roles inside one business (#51). Everything here is tenant-scoped by the
 * global scope, so a role id from another business simply is not found.
 *
 * The editor only ever offers permissions the plan supports
 * ({@see PermissionService::grantableCodes()}), so an owner is never invited to
 * tick a box that layer 1 would veto anyway (#125). Codes already granted but
 * currently dormant are shown separately and preserved on save.
 */
class RoleController extends Controller
{
    public function __construct(
        protected RoleService $roles,
        protected PermissionService $permissions,
    ) {}

    public function index(): View
    {
        $roles = Role::query()
            ->ordered()
            ->with('permissions')
            ->withCount('users')
            ->get();

        return view('app.roles.index', [
            'roles' => $roles,
            'dormant' => $roles->mapWithKeys(fn (Role $role) => [
                $role->id => $this->permissions->dormantCodesFor($role),
            ]),
            'registry' => PermissionRegistry::all(),
        ]);
    }

    public function create(): View
    {
        return view('app.roles.create', $this->formData(new Role));
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create($request->roleAttributes(), $request->permissionCodes());

        return redirect()
            ->route('app.roles.index')
            ->with('success', "Role \"{$role->name}\" created.");
    }

    public function edit(Role $role): View
    {
        return view('app.roles.edit', $this->formData($role));
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update($role, $request->roleAttributes(), $request->permissionCodes());

        return redirect()
            ->route('app.roles.index')
            ->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (! $this->roles->delete($role)) {
            return back()->with('error', $role->is_system
                ? 'Starter roles cannot be deleted — edit its permissions instead.'
                : 'That role is assigned to someone. Move them to another role first.');
        }

        return redirect()
            ->route('app.roles.index')
            ->with('success', 'Role deleted.');
    }

    /** @return array<string, mixed> */
    protected function formData(Role $role): array
    {
        return [
            'role' => $role,
            'groups' => $this->permissions->grantableGrouped(),
            'granted' => $role->exists ? $role->permissionCodes() : [],
            'dormantCodes' => $role->exists ? $this->permissions->dormantCodesFor($role) : [],
            'registry' => PermissionRegistry::all(),
        ];
    }
}
