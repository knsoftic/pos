<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        $options = ['Size' => fake()->randomElement(['S', 'M', 'L', 'XL'])];
        $cost = fake()->randomFloat(2, 50, 900);

        return [
            'business_id' => Business::factory(),
            'product_id' => Product::factory(),
            'name' => ProductVariant::nameFromOptions($options),
            'sku' => 'VAR-'.Str::upper(Str::random(8)),
            'barcode' => null,
            'options' => $options,
            'cost_price' => $cost,
            'selling_price' => round($cost * 1.35, 2),
            'alert_quantity' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function of(Product $product): static
    {
        return $this->state(fn () => [
            'business_id' => $product->business_id,
            'product_id' => $product->id,
        ]);
    }

    /** @param array<string, string> $options */
    public function withOptions(array $options): static
    {
        return $this->state(fn () => [
            'options' => $options,
            'name' => ProductVariant::nameFromOptions($options),
        ]);
    }
}
