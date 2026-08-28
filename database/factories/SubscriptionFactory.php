<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'plan_id' => Plan::factory()->monthly(),
            'billing_cycle' => BillingCycle::Monthly,
            'price' => 29.00,
            'currency' => config('subscription.currency'),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'trial_ends_at' => null,
        ];
    }

    /** On trial: `ends_at` tracks the trial end, as the service sets it. */
    public function trial(int $days = 14): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Trial,
            'price' => 0,
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays($days),
            'ends_at' => now()->addDays($days),
        ]);
    }

    /**
     * Lapsed and past grace. `grace_days` is pinned to 0 so the test does not
     * depend on the configured default still being small enough.
     */
    public function expired(int $daysAgo = 5): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subDays($daysAgo + 30),
            'ends_at' => now()->subDays($daysAgo),
            'grace_days' => 0,
        ]);
    }

    /** Expired but inside the courtesy window — still has access. #127 */
    public function inGrace(int $daysAgo = 1, int $graceDays = 3): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subDays($daysAgo + 30),
            'ends_at' => now()->subDays($daysAgo),
            'grace_days' => $graceDays,
        ]);
    }

    public function cancelled(?string $reason = 'Testing'): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /** Never expires. #174 */
    public function lifetime(): static
    {
        return $this->state(fn () => [
            'billing_cycle' => BillingCycle::Lifetime,
            'status' => SubscriptionStatus::Active,
            'ends_at' => null,
            'trial_ends_at' => null,
        ]);
    }

    /** An older row already replaced by a newer one. #176 */
    public function superseded(): static
    {
        return $this->state(fn () => ['superseded_at' => now()]);
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addDays($days),
        ]);
    }

    public function forBusiness(Business $business): static
    {
        return $this->state(fn () => ['business_id' => $business->id]);
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id]);
    }
}
