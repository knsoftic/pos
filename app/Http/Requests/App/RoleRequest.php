<?php

namespace App\Http\Requests\App;

use App\Models\Role;
use App\Services\RoleService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the role editor (#51).
 *
 * Note what is NOT validated here: whether each submitted permission is one the
 * plan currently allows. That is an entitlement question, and answering it in a
 * form request would mean two places could disagree — {@see RoleService}
 * intersects the submission with the grantable set instead, so a stale form or a
 * hand-crafted post can only ever grant less than it asked for, never more.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already ran `permission:roles.manage`; this is the same
        // question asked from the other side, so a missing middleware cannot
        // silently open the form up.
        return $this->user() !== null && $this->user()->can(PermissionRegistry::ROLES_MANAGE);
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'required', 'string', 'max:60',
                // Two roles with the same name in one business would be a
                // usability trap; across businesses it is fine and expected.
                Rule::unique('roles', 'name')
                    ->where('business_id', $this->user()->business_id)
                    ->whereNull('deleted_at')
                    ->ignore($roleId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a role with that name.',
        ];
    }

    /** @return array{name: string, description: string|null} */
    public function roleAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->input('description') ?: null,
        ];
    }

    /**
     * Submitted codes, filtered down to ones the registry actually defines —
     * unknown strings are dropped rather than stored.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return array_values(array_filter(
            (array) $this->input('permissions', []),
            fn ($code) => is_string($code) && PermissionRegistry::exists($code),
        ));
    }

    public function role(): ?Role
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role : null;
    }
}
