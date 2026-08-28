<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 *
 * ⚠️ For ARRANGING a test only. Production code must never write a stock figure
 * directly — {@see InventoryService} owns that, so the ledger and
 * the balance always agree. A factory-made row has no ledger behind it, which is
 * fine for "given a shelf with 10 on it" and wrong for anything else.
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'quantity' => 0,
            'average_cost' => 0,
        ];
    }

    public function on(Branch $branch, Product $product, float $quantity = 0, float $averageCost = 0): static
    {
        return $this->state(fn () => [
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'average_cost' => $averageCost,
        ]);
    }
}
