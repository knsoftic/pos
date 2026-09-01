<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBatch>
 *
 * ⚠️ Arranging only. A factory-made batch has no ledger behind it and does not
 * move the shelf total — production writes batches exclusively through
 * InventoryService, so the two always agree.
 */
class StockBatchFactory extends Factory
{
    protected $model = StockBatch::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'batch_number' => 'LOT-'.fake()->numberBetween(1000, 9999),
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'quantity' => 10,
            'unit_cost' => 100,
            'received_at' => now(),
        ];
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => ['expiry_date' => now()->addDays($days)->toDateString()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expiry_date' => now()->subDays(3)->toDateString()]);
    }

    public function noExpiry(): static
    {
        return $this->state(fn () => ['expiry_date' => null]);
    }
}
