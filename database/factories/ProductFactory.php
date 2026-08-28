<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));
        $cost = fake()->randomFloat(2, 50, 900);

        return [
            'business_id' => Business::factory(),
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'type' => ProductType::Standard,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'barcode' => null,
            'description' => fake()->sentence(),
            'cost_price' => $cost,
            // A believable markup, so margin figures in tests are not absurd.
            'selling_price' => round($cost * 1.35, 2),
            'tax_rate' => null,
            'track_inventory' => true,
            'alert_quantity' => 5,
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'type' => ProductType::Service,
            'track_inventory' => false,
            'alert_quantity' => null,
        ]);
    }

    public function variable(): static
    {
        return $this->state(fn () => ['type' => ProductType::Variable]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function in(?Category $category = null, ?Brand $brand = null, ?Unit $unit = null): static
    {
        return $this->state(fn () => array_filter([
            'category_id' => $category?->id,
            'brand_id' => $brand?->id,
            'unit_id' => $unit?->id,
        ], fn ($value) => $value !== null));
    }
}
