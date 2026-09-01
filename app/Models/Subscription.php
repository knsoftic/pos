<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\SubscriptionService;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business's subscription to a plan.
 *
 * TENANT-SCOPED: under /app a business only ever sees its own rows; the operator
 * console has no tenant context and therefore sees all of them.
 *
 * APPEND-ONLY HISTORY (#176): renew / upgrade / downgrade never mutate an
 * existing row — {@see SubscriptionService} stamps `superseded_at`
 * on the old one and inserts a new one, inside a transaction. `price`,
 * `currency` and `billing_cycle` are snapshots, so re-pricing a plan tomorrow
 * cannot rewrite what a tenant was charged today. #198
 *
 * ⚠️ Never trust the `status` column to decide access. It records the operator's
 * intent; whether the subscription has actually run out is derived from the
 * dates by {@see self::effectiveStatus()}. A row left at 'active' by a missed
 * cron run must not keep an expired tenant working. #79
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * SECURITY: `business_id` is not fillable — a subscription must never be
     * moved between tenants by mass assignment. It is stamped by BelongsToTenant
     * from the active context, or set explicitly by the service layer. #132
     *
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'billing_cycle',
        'price',
        'currency',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'grace_days',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => SubscriptionStatus::class,
            'price' => 'decimal:2',
            'grace_days' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'superseded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------- relations

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    // --------------------------------------------------------------- scopes

    /** The live subscription — the one not yet replaced by a newer row. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at')->latest('id');
    }

    /** Historical rows, newest first. #176 */
    public function scopeHistory(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /** Rows whose paid period ends inside the next N days — for reminders. #11 */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNull('superseded_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays($days)]);
    }

    // -------------------------------------------------------- state (derived)

    /**
     * The status that actually governs access, computed from dates rather than
     * read from the column. Grace is intentionally reported as Active — access
     * continues, and {@see self::isInGrace()} lets the UI warn about it.
     */
    public function effectiveStatus(): SubscriptionStatus
    {
        if ($this->cancelled_at !== null) {
            return SubscriptionStatus::Cancelled;
        }

        if ($this->trial_ends_at !== null && $this->trial_ends_at->isFuture()) {
            return SubscriptionStatus::Trial;
        }

        // Lifetime plans have no end date. #174
        if ($this->ends_at === null) {
            return SubscriptionStatus::Active;
        }

        if ($this->ends_at->isFuture()) {
            return SubscriptionStatus::Active;
        }

        // Past the paid period, but grace may still be running. #127
        if ($this->graceEndsAt()?->isFuture()) {
            return SubscriptionStatus::Active;
        }

        return SubscriptionStatus::Expired;
    }

    /** May the tenant use the app right now? */
    public function grantsAccess(): bool
    {
        return $this->effectiveStatus()->grantsAccess();
    }

    public function isOnTrial(): bool
    {
        return $this->effectiveStatus() === SubscriptionStatus::Trial;
    }

    public function isExpired(): bool
    {
        return $this->effectiveStatus() === SubscriptionStatus::Expired;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /** Lifetime subscriptions never expire. #174 */
    public function neverExpires(): bool
    {
        return $this->ends_at === null && ! $this->isCancelled();
    }

    /** Inside the post-expiry courtesy window: still working, but warn loudly. */
    public function isInGrace(): bool
    {
        if ($this->ends_at === null || $this->ends_at->isFuture() || $this->isCancelled()) {
            return false;
        }

        return (bool) $this->graceEndsAt()?->isFuture();
    }

    /** Grace resolution: subscription → plan → system default. #127 */
    public function graceDays(): int
    {
        return $this->grace_days
            ?? $this->plan?->graceDays()
            ?? (int) config('subscription.grace_days');
    }

    public function graceEndsAt(): ?CarbonInterface
    {
        return $this->ends_at?->copy()->addDays($this->graceDays());
    }

    /**
     * Whole days until the paid period ends. NULL = never expires. Never
     * negative — once past, it is simply 0.
     */
    public function daysRemaining(): ?int
    {
        if ($this->ends_at === null) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay(), false));
    }

    public function graceDaysRemaining(): ?int
    {
        $graceEnd = $this->graceEndsAt();

        if ($graceEnd === null || ! $this->isInGrace()) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($graceEnd->copy()->startOfDay(), false));
    }

    /**
     * Which configured warning window we are inside (#11) — e.g. 7, 3 or 1.
     * NULL when expiry is not near (or never comes).
     */
    public function expiryWarningThreshold(): ?int
    {
        $days = $this->daysRemaining();

        if ($days === null) {
            return null;
        }

        $thresholds = (array) config('subscription.warning_days');
        sort($thresholds);

        foreach ($thresholds as $threshold) {
            if ($days <= (int) $threshold) {
                return (int) $threshold;
            }
        }

        return null;
    }

    public function formattedPrice(): string
    {
        return ($this->currency === config('subscription.currency')
                ? config('subscription.currency_symbol')
                : $this->currency.' ')
            .number_format((float) $this->price, (int) config('subscription.currency_decimals'));
    }

    /** Total settled money received against this subscription. */
    public function amountPaid(): float
    {
        return (float) $this->payments()
            ->where('status', PaymentStatus::Paid)
            ->sum('amount');
    }
}
