<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 *
 * ⚠️ Same caveat as {@see StockFactory}: a movement made here does NOT update
 * the cached balance, because it never went through InventoryService. Use it to
 * arrange ledger history, not to simulate stock moving.
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'type' => StockMovementType::Purchase,
            'quantity' => 10,
            'unit_cost' => 100,
            'balance_after' => 10,
            'created_at' => now(),
        ];
    }

    public function of(Branch $branch, Product $product): static
    {
        return $this->state(fn () => [
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
        ]);
    }

    public function type(StockMovementType $type, float $quantity): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'quantity' => $quantity,
        ]);
    }
}
