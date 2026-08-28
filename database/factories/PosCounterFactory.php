<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\PosCounter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosCounter>
 */
class PosCounterFactory extends Factory
{
    protected $model = PosCounter::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'name' => 'Counter '.fake()->numberBetween(1, 9),
            'code' => 'POS'.Str::upper(Str::random(5)),
            'is_active' => true,
        ];
    }

    /** Keep the counter and its branch in the same business — the usual case. */
    public function inBranch(Branch $branch): static
    {
        return $this->state(fn () => [
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
