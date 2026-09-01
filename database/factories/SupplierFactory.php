<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 *
 * ⚠️ Same caveat as CustomerFactory: `balance` is a cache over the ledger.
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->company(),
            'code' => 'S-'.Str::upper(Str::random(8)),
            'contact_person' => fake()->name(),
            'phone' => fake()->numerify('042 #######'),
            'email' => fake()->unique()->companyEmail(),
            'city' => fake()->city(),
            'payment_terms_days' => 30,
            'balance' => 0,
            'is_active' => true,
        ];
    }

    public function blocked(string $reason = 'Quality dispute'): static
    {
        return $this->state(fn () => ['is_active' => false, 'blocked_reason' => $reason]);
    }
}
