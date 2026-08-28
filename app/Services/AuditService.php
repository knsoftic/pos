<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Central writer for the immutable audit trail (#61, #133, #177).
 *
 * Every sensitive action — logins, permission/limit overrides, invoice
 * reprints, voids, subscription changes — goes through here so the trail has
 * one consistent shape. Nothing else should write to `audit_logs` directly.
 *
 * Design notes:
 *  - The tenant is taken from TenantContext when set, otherwise from the
 *    subject/actor. Super-admin actions have a null business_id (system-wide).
 *  - Writes never throw into the caller's flow: an audit failure must not roll
 *    back a legitimate business transaction. Failures are logged instead.
 */
class AuditService
{
    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public function __construct(protected TenantContext $tenant) {}

    /**
     * Record an event.
     *
     * @param  string  $event  Machine-readable event code, e.g. 'auth.login'
     * @param  Model|null  $auditable  The record the event is about (optional)
     * @param  array<string, mixed>  $properties  Extra structured detail (old/new values…)
     */
    public function log(
        string $event,
        ?Model $auditable = null,
        ?string $description = null,
        array $properties = [],
        ?Model $actor = null,
        ?int $businessId = null,
    ): ?AuditLog {
        $actor ??= $this->resolveActor();

        $payload = [
            'business_id' => $businessId ?? $this->resolveBusinessId($actor, $auditable),
            'actor_type' => $this->actorType($actor),
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'created_at' => now(),
        ];

        if ($auditable !== null) {
            $payload['auditable_type'] = $auditable->getMorphClass();
            $payload['auditable_id'] = $auditable->getKey();
        }

        try {
            return AuditLog::create($payload);
        } catch (\Throwable $e) {
            // An unwritable audit row must never break the business action. #94
            report($e);

            return null;
        }
    }

    /**
     * Convenience helper for "field changed" style entries (#177).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logChange(string $event, Model $auditable, array $before, array $after, ?string $description = null): ?AuditLog
    {
        return $this->log($event, $auditable, $description, [
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Whichever guard is authenticated wins; admin is checked first because a
     * super admin acting on a tenant is the more privileged actor.
     */
    protected function resolveActor(): ?Model
    {
        return Auth::guard('admin')->user() ?? Auth::guard('web')->user();
    }

    protected function actorType(?Model $actor): string
    {
        return match (true) {
            $actor instanceof Admin => self::ACTOR_ADMIN,
            $actor instanceof User => self::ACTOR_USER,
            default => self::ACTOR_SYSTEM,
        };
    }

    /**
     * Tenant resolution order: active context → the actor's business → the
     * audited record's business. Super-admin/system events stay null.
     */
    protected function resolveBusinessId(?Model $actor, ?Model $auditable): ?int
    {
        if ($this->tenant->hasBusiness()) {
            return $this->tenant->businessId();
        }

        if ($actor instanceof User) {
            return $actor->business_id;
        }

        if ($auditable !== null && isset($auditable->business_id)) {
            return (int) $auditable->business_id;
        }

        return null;
    }
}
