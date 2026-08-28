<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Auto-stamps `created_by` / `updated_by` with the acting principal's id.
 * Apply to tenant models that have those columns (added from Phase 4 onward).
 * Prefers the business user (web guard), falling back to the super admin.
 */
trait Blameable
{
    public static function bootBlameable(): void
    {
        static::creating(function ($model): void {
            $actorId = static::currentActorId();

            if ($actorId !== null) {
                $model->created_by ??= $actorId;
                $model->updated_by ??= $actorId;
            }
        });

        static::updating(function ($model): void {
            $actorId = static::currentActorId();

            if ($actorId !== null) {
                $model->updated_by = $actorId;
            }
        });
    }

    protected static function currentActorId(): ?int
    {
        return Auth::guard('web')->id() ?? Auth::guard('admin')->id();
    }
}
