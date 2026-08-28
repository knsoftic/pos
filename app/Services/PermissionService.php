<?php

namespace App\Services;

use App\Exceptions\PermissionDeniedException;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * The three-layer access check, in one place (#187, #188).
 *
 *   Layer 1 — SUBSCRIPTION FEATURE. Does the plan include the capability this
 *             permission rides on? A role may grant "Process returns" all it
 *             likes; if returns are not in the plan, the answer is no.
 *   Layer 2 — USER PERMISSION. Does this person's role grant the code? The
 *             business owner is above roles and always passes.
 *   Layer 3 — TENANT. Is this user actually inside the business the request is
 *             running for? Global scopes already guarantee this, so it is
 *             belt-and-braces — but a permission check that quietly said yes for
 *             a user from another tenant would be the worst possible bug, so it
 *             is checked explicitly and cheaply here too.
 *
 * ORDER MATTERS for the message the user gets: feature first, so a tenant on the
 * wrong plan is told to upgrade rather than told they lack a permission their
 * owner cannot even grant them.
 *
 * All three layers are re-checked on every call; the only thing cached is the
 * role's permission list, per request, per user.
 */
class PermissionService
{
    /** @var array<int, list<string>> user id => granted codes */
    protected array $roleMemo = [];

    public function __construct(
        protected FeatureService $features,
        protected TenantContext $tenant,
    ) {}

    /**
     * May the user do this? Defaults to the authenticated business user.
     */
    public function allows(string $code, ?User $user = null): bool
    {
        $this->assertKnown($code);

        $user ??= Auth::guard('web')->user();

        if ($user === null || ! $user->is_active) {
            return false;
        }

        // Layer 3 — tenant.
        if (! $this->sameTenant($user)) {
            return false;
        }

        // Layer 1 — subscription feature.
        if (! $this->featureAllows($code, $user)) {
            return false;
        }

        // Layer 2 — role. The owner outranks the role system entirely.
        if ($user->isOwner()) {
            return true;
        }

        return in_array($code, $this->grantedCodes($user), true);
    }

    public function denies(string $code, ?User $user = null): bool
    {
        return ! $this->allows($code, $user);
    }

    /** True only if every code passes. */
    public function allOf(array $codes, ?User $user = null): bool
    {
        foreach ($codes as $code) {
            if (! $this->allows($code, $user)) {
                return false;
            }
        }

        return true;
    }

    /** True if at least one code passes. */
    public function anyOf(array $codes, ?User $user = null): bool
    {
        foreach ($codes as $code) {
            if ($this->allows($code, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backend gate for controllers and services: allow, or throw.
     *
     * @throws PermissionDeniedException
     */
    public function authorize(string $code, ?User $user = null): void
    {
        if (! $this->allows($code, $user)) {
            throw new PermissionDeniedException($code);
        }
    }

    /**
     * The full resolved map (code => bool) for building navigation and hiding
     * buttons. Views ask this once instead of calling allows() per link.
     *
     * @return array<string, bool>
     */
    public function all(?User $user = null): array
    {
        $map = [];

        foreach (PermissionRegistry::codes() as $code) {
            $map[$code] = $this->allows($code, $user);
        }

        return $map;
    }

    /**
     * Codes an owner may actually hand out right now — i.e. those whose feature
     * is in the plan (#125). The role editor shows only these, so nobody ticks a
     * box that layer 1 will veto anyway.
     *
     * @return list<string>
     */
    public function grantableCodes(): array
    {
        return array_values(array_filter(
            PermissionRegistry::codes(),
            fn (string $code) => $this->featureAllows($code),
        ));
    }

    /**
     * Same thing, grouped for the role editor UI.
     *
     * @return array<string, array<string, array{name: string, group: string, description: string, sensitive: bool, feature: string|null}>>
     */
    public function grantableGrouped(): array
    {
        $grantable = $this->grantableCodes();
        $grouped = [];

        foreach (PermissionRegistry::all() as $code => $meta) {
            if (in_array($code, $grantable, true)) {
                $grouped[$meta['group']][$code] = $meta;
            }
        }

        return $grouped;
    }

    /**
     * Codes a role grants that are NOT currently usable because the plan lost
     * the feature. Shown to the owner as "kept, but inactive" rather than being
     * silently dropped — a downgrade must never quietly rewrite their roles.
     *
     * @return list<string>
     */
    public function dormantCodesFor(Role $role): array
    {
        return array_values(array_filter(
            $role->permissionCodes(),
            fn (string $code) => ! $this->featureAllows($code),
        ));
    }

    /** Drop the memoised role lookup (after a role is edited). */
    public function flush(?int $userId = null): void
    {
        if ($userId === null) {
            $this->roleMemo = [];

            return;
        }

        unset($this->roleMemo[$userId]);
    }

    // ------------------------------------------------------------- internals

    protected function assertKnown(string $code): void
    {
        if (! PermissionRegistry::exists($code)) {
            throw new \InvalidArgumentException(
                "Unknown permission code [{$code}]. Add it to ".PermissionRegistry::class.'.'
            );
        }
    }

    /**
     * Layer 1. A permission with no feature attached is always available — those
     * are the ones every plan can do (viewing products, managing settings).
     */
    protected function featureAllows(string $code, ?User $user = null): bool
    {
        $feature = PermissionRegistry::featureFor($code);

        if ($feature === null) {
            return true;
        }

        return $this->features->enabled($feature, $user?->business_id);
    }

    /**
     * Layer 3. When no tenant context is active (console, tests, super-admin
     * code) there is nothing to compare against and the check passes; the caller
     * is expected to be scoping explicitly, exactly as with TenantScope.
     */
    protected function sameTenant(User $user): bool
    {
        $businessId = $this->tenant->businessId();

        return $businessId === null || (int) $user->business_id === $businessId;
    }

    /** @return list<string> */
    protected function grantedCodes(User $user): array
    {
        $key = (int) $user->id;

        if (array_key_exists($key, $this->roleMemo)) {
            return $this->roleMemo[$key];
        }

        $role = $user->relationLoaded('role') ? $user->role : $user->role()->first();

        if ($role === null) {
            return $this->roleMemo[$key] = [];
        }

        $role->loadMissing('permissions');

        return $this->roleMemo[$key] = $role->permissionCodes();
    }
}
