<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SaleReturn>
 *
 * ⚠️ Arranging only. A factory-made return has posted nothing — no stock, no
 * refund, no credit. Use SaleReturnService for one that actually happened.
 */
class SaleReturnFactory extends Factory
{
    protected $model = SaleReturn::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'sale_id' => Sale::factory(),
            'reference' => 'RET-'.Str::upper(Str::random(6)),
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'subtotal' => 0,
            'total' => 0,
        ];
    }
}
