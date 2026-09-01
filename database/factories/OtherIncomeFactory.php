<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\OtherIncome;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OtherIncome>
 *
 * ⚠️ Arranging only — see ExpenseFactory.
 */
class OtherIncomeFactory extends Factory
{
    protected $model = OtherIncome::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'reference' => 'INC-'.Str::upper(Str::random(6)),
            'income_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 100, 3000),
            'payment_method' => 'cash',
            'source' => fake()->randomElement(['Scrap sale', 'Sublet rent', 'Supplier rebate', 'Insurance claim']),
        ];
    }
}
