<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Expense>
 *
 * ⚠️ Arranging only. A factory-made expense has moved no drawer and written no
 * audit line. Use ExpenseService for one that actually happened.
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'reference' => 'EXP-'.Str::upper(Str::random(6)),
            'expense_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'payment_method' => 'cash',
            'payee' => fake()->company(),
        ];
    }
}
