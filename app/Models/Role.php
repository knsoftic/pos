<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use App\Support\PermissionRegistry;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A role inside one business (#51). Tenant-scoped: a role is never visible to,
 * or assignable from, another business.
 *
 * Permissions are plain code strings in `role_permissions` — see
 * {@see PermissionRegistry} for why the vocabulary lives in code. Reading them
 * back through `permissionCodes()` filters out anything the registry no longer
 * knows, so a removed permission cannot linger as a live grant.
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /**
     * `business_id` and `is_system` are guarded: the first for the usual tenancy
     * reason, the second because a request that could mark its own role as a
     * system preset would make it undeletable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    // ------------------------------------------------------------- relations

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_system')->orderBy('name');
    }

    // --------------------------------------------------------------- helpers

    /**
     * The permission codes this role grants, minus anything the registry no
     * longer defines.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return $this->permissions
            ->pluck('permission')
            ->filter(fn (string $code) => PermissionRegistry::exists($code))
            ->values()
            ->all();
    }

    public function grants(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }

    /** @return list<string> */
    public function sensitivePermissionCodes(): array
    {
        return array_values(array_filter(
            $this->permissionCodes(),
            fn (string $code) => PermissionRegistry::isSensitive($code),
        ));
    }

    /**
     * A role in use may not be deleted (#104) — reassign those people first.
     * Counts soft-deleted users too: restoring one must not resurrect a pointer
     * to a role that is gone.
     */
    public function isInUse(): bool
    {
        return $this->users()->withoutGlobalScope(SoftDeletingScope::class)->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->is_system && ! $this->isInUse();
    }
}
