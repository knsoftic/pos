<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 *
 * ⚠️ `balance` is left at zero on purpose. It is a cache over the ledger, so a
 * factory that set it directly would arrange a state the app can never reach.
 * Post through CustomerLedgerService instead.
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->name(),
            'code' => 'C-'.Str::upper(Str::random(8)),
            'phone' => fake()->numerify('03## #######'),
            'email' => fake()->unique()->safeEmail(),
            'city' => fake()->city(),
            'credit_limit' => 0,
            'balance' => 0,
            'is_active' => true,
        ];
    }

    /** An account customer, with room to run a tab. */
    public function withCredit(float $limit = 50000): static
    {
        return $this->state(fn () => ['credit_limit' => $limit]);
    }

    public function unlimitedCredit(): static
    {
        return $this->state(fn () => ['credit_limit' => null]);
    }

    public function blocked(string $reason = 'Repeated late payment'): static
    {
        return $this->state(fn () => ['is_active' => false, 'blocked_reason' => $reason]);
    }
}
