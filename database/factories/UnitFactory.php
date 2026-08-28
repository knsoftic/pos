<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Piece',
            'short_name' => 'pc'.Str::lower(Str::random(3)),
            'base_unit_id' => null,
            'conversion_factor' => 1,
            'allows_decimals' => false,
            'is_active' => true,
        ];
    }

    /** A derived unit: "$factor of $base makes one of these". */
    public function derivedFrom(Unit $base, float $factor): static
    {
        return $this->state(fn () => [
            'business_id' => $base->business_id,
            'base_unit_id' => $base->id,
            'conversion_factor' => $factor,
        ]);
    }

    public function weight(): static
    {
        return $this->state(fn () => [
            'name' => 'Kilogram',
            'short_name' => 'kg'.Str::lower(Str::random(3)),
            'allows_decimals' => true,
        ]);
    }
}
