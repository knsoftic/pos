<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant root. NOT itself tenant-scoped — it defines the tenants. Every
 * business-owned record elsewhere references this via `business_id`.
 */
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'logo_path',
        'status',
        'timezone',
        'locale',
        'created_by',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('is_business_owner', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    // ------------------------------------------------ subscription (Phase 2)

    /** Every subscription this business has ever had — the history. #176 */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The live subscription: the one row not yet replaced by a newer one.
     *
     * hasOne + latestOfMany so it can be eager-loaded across a list of
     * businesses without an N+1 (the operator console lists dozens at a time).
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereNull('superseded_at')
            ->latestOfMany();
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** Operator-set per-tenant feature grants/revokes. #10 */
    public function featureOverrides(): HasMany
    {
        return $this->hasMany(BusinessFeatureOverride::class);
    }

    /** Operator-set per-tenant quota overrides. #10 */
    public function limitOverrides(): HasMany
    {
        return $this->hasMany(BusinessLimitOverride::class);
    }

    /** ⚠️ Operator-only private support notes — never expose under /app. #159 */
    public function notes(): HasMany
    {
        return $this->hasMany(BusinessNote::class);
    }

    // -------------------------------------------------------------- helpers

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /** @return array<string, string> value => label, for status <select>s. */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_SUSPENDED => 'Suspended',
            self::STATUS_INACTIVE => 'Inactive',
        ];
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'badge-green',
            self::STATUS_SUSPENDED => 'badge-amber',
            default => 'badge-slate',
        };
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
