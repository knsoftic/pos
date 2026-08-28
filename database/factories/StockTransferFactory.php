<?php

namespace Database\Factories;

use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockTransfer>
 *
 * For arranging a test. A factory-made transfer has posted no stock movements,
 * so only use it for states where nothing should have moved yet (drafts) — for
 * anything further, drive StockTransferService, which is what production does.
 */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'reference' => 'TRF-'.Str::upper(Str::random(6)),
            'from_branch_id' => Branch::factory(),
            'to_branch_id' => Branch::factory(),
            'status' => TransferStatus::Draft,
            'notes' => null,
        ];
    }

    public function between(Branch $from, Branch $to): static
    {
        return $this->state(fn () => [
            'business_id' => $from->business_id,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
        ]);
    }
}
