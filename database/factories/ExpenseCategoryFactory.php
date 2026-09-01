<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExpenseCategory> */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Rent', 'Utilities', 'Salaries', 'Transport', 'Marketing', 'Repairs', 'Insurance',
        ]).' '.Str::upper(Str::random(4));

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
