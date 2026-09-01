<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'purchase_id' => Purchase::factory(),
            'product_id' => Product::factory(),
            'description' => 'A line',
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 100,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'line_total' => 1000,
        ];
    }
}
