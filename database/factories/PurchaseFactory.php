<?php

namespace Database\Factories;

use App\Enums\PurchaseStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Purchase>
 *
 * ⚠️ Arranging only. A factory-made purchase has posted nothing — no stock, no
 * supplier ledger. Use PurchaseService to make one that actually happened.
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'supplier_id' => Supplier::factory(),
            'reference' => 'PO-'.Str::upper(Str::random(6)),
            'status' => PurchaseStatus::Draft,
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'total' => 0,
        ];
    }

    public function status(PurchaseStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
