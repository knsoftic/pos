<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => Str::title($name).' Plan',
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Hidden from the public pricing page — a bespoke deal. #84 */
    public function private(): static
    {
        return $this->state(fn () => ['is_public' => false]);
    }

    public function trialDays(int $days): static
    {
        return $this->state(fn () => ['trial_days' => $days]);
    }

    public function graceDays(int $days): static
    {
        return $this->state(fn () => ['grace_days' => $days]);
    }

    /**
     * A monthly price. Most tests need a plan that can actually be subscribed
     * to, and SubscriptionService refuses a plan with no price for the cycle.
     */
    public function monthly(float $price = 29.00): static
    {
        return $this->afterCreating(fn (Plan $plan) => $plan->prices()->create([
            'billing_cycle' => BillingCycle::Monthly,
            'price' => $price,
            'is_active' => true,
        ]));
    }

    public function yearly(float $price = 290.00): static
    {
        return $this->afterCreating(fn (Plan $plan) => $plan->prices()->create([
            'billing_cycle' => BillingCycle::Yearly,
            'price' => $price,
            'is_active' => true,
        ]));
    }

    /** Never expires. #174 */
    public function lifetime(float $price = 999.00): static
    {
        return $this->afterCreating(fn (Plan $plan) => $plan->prices()->create([
            'billing_cycle' => BillingCycle::Lifetime,
            'price' => $price,
            'is_active' => true,
        ]));
    }

    public function free(): static
    {
        return $this->afterCreating(fn (Plan $plan) => $plan->prices()->create([
            'billing_cycle' => BillingCycle::Monthly,
            'price' => 0,
            'is_active' => true,
        ]));
    }

    /**
     * Attach features by code, all enabled.
     *
     * @param  list<string>  $codes
     */
    public function withFeatures(array $codes): static
    {
        return $this->afterCreating(function (Plan $plan) use ($codes): void {
            $features = \App\Models\Feature::query()->whereIn('code', $codes)->get();

            foreach ($features as $feature) {
                $plan->features()->syncWithoutDetaching([
                    $feature->id => ['is_enabled' => true],
                ]);
            }
        });
    }

    /**
     * Attach limits by code. NULL value = unlimited, matching the resolver.
     *
     * @param  array<string, int|null>  $limits  code => value
     */
    public function withLimits(array $limits): static
    {
        return $this->afterCreating(function (Plan $plan) use ($limits): void {
            foreach ($limits as $code => $value) {
                $limit = \App\Models\Limit::query()->where('code', $code)->first();

                if ($limit === null) {
                    continue;
                }

                $plan->limits()->syncWithoutDetaching([$limit->id => ['value' => $value]]);
            }
        });
    }
}
