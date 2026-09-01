<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sale>
 *
 * ⚠️ Arranging only. A factory-made sale has posted nothing — no stock, no
 * customer ledger, no cash session. Use SaleService for one that happened.
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'status' => SaleStatus::Completed,
            'sold_at' => now(),
            'sale_date' => now()->toDateString(),
            'subtotal' => 0,
            'total' => 0,
        ];
    }

    public function held(): static
    {
        return $this->state(fn () => ['status' => SaleStatus::Held, 'sold_at' => null]);
    }
}
