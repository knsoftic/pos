<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseReturn>
 */
class PurchaseReturnFactory extends Factory
{
    protected $model = PurchaseReturn::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'purchase_id' => Purchase::factory(),
            'supplier_id' => Supplier::factory(),
            'reference' => 'PR-'.Str::upper(Str::random(6)),
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged in transit',
            'subtotal' => 0,
            'total' => 0,
        ];
    }
}
