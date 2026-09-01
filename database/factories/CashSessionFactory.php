<?php

namespace Database\Factories;

use App\Enums\CashSessionStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CashSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'status' => CashSessionStatus::Open,
            'opened_at' => now(),
            'opening_float' => 5000,
        ];
    }

    public function closed(float $counted = 5000): static
    {
        return $this->state(fn () => [
            'status' => CashSessionStatus::Closed,
            'closed_at' => now(),
            'expected_cash' => 5000,
            'counted_cash' => $counted,
            'difference' => $counted - 5000,
        ]);
    }
}
