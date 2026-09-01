<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TaxRate> */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Rate '.Str::upper(Str::random(4)),
            'rate' => fake()->randomElement([0, 5, 10, 17]),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
