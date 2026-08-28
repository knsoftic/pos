<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'status' => Business::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'locale' => 'en',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Business::STATUS_SUSPENDED]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Business::STATUS_INACTIVE]);
    }
}
