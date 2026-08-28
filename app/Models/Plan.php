<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A subscription plan (#7). Operator-owned, NOT tenant-scoped.
 *
 * A plan is a bundle of three things, all database-driven (#190):
 *   prices    → {@see PlanPrice}, one row per billing cycle (#175)
 *   features  → `plan_feature` pivot (#9)
 *   limits    → `plan_limit` pivot (#8)
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'badge',
        'trial_days',
        'grace_days',
        'is_active',
        'is_public',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ------------------------------------------------------------- relations

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_feature')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    public function limits(): BelongsToMany
    {
        return $this->belongsToMany(Limit::class, 'plan_limit')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    // --------------------------------------------------------------- scopes

    /** Assignable to businesses. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Listed on the public pricing page — "Show on Website". #172 */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // -------------------------------------------------------------- helpers

    public function price(BillingCycle $cycle): ?PlanPrice
    {
        return $this->prices->firstWhere(
            fn (PlanPrice $price) => $price->billing_cycle === $cycle && $price->is_active
        );
    }

    /** @return Collection<int, PlanPrice> */
    public function activePrices(): Collection
    {
        return $this->prices->where('is_active', true)->sortBy('price')->values();
    }

    /** The cheapest recurring price — what the pricing page leads with. */
    public function entryPrice(): ?PlanPrice
    {
        return $this->activePrices()
            ->filter(fn (PlanPrice $p) => $p->billing_cycle->isRecurring())
            ->sortBy('price')
            ->first();
    }

    /** A free plan is simply one that costs nothing on every cycle. #173 */
    public function isFree(): bool
    {
        $prices = $this->activePrices();

        return $prices->isNotEmpty() && $prices->every(fn (PlanPrice $p) => (float) $p->price === 0.0);
    }

    /** A lifetime plan is one that sells a `lifetime` cycle. #174 */
    public function hasLifetime(): bool
    {
        return $this->activePrices()->contains(
            fn (PlanPrice $p) => $p->billing_cycle === BillingCycle::Lifetime
        );
    }

    /** Effective trial length: the plan's own, else the system default. #81 */
    public function trialDays(): int
    {
        return $this->trial_days ?? (int) config('subscription.trial_days');
    }

    /** Effective grace period: the plan's own, else the system default. #127 */
    public function graceDays(): int
    {
        return $this->grace_days ?? (int) config('subscription.grace_days');
    }

    /** Whether the plan may be hard-deleted, or must be archived instead. #104 */
    public function isInUse(): bool
    {
        return $this->subscriptions()->exists();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
