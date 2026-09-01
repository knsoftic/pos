<?php

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Models\Business;
use App\Models\Customer;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<LedgerEntry>
 *
 * ⚠️ Arranging only. An entry made here does NOT move the party's cached
 * balance, because it never went through PartyLedgerService. Use it to set up
 * history, not to simulate money moving.
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'party_type' => (new Customer)->getMorphClass(),
            'party_id' => Customer::factory(),
            'type' => LedgerEntryType::Sale,
            'debit' => 1000,
            'credit' => 0,
            'balance_after' => 1000,
            'entry_date' => now()->toDateString(),
            'created_at' => now(),
        ];
    }

    public function forParty(Model $party): static
    {
        return $this->state(fn () => [
            'business_id' => $party->business_id,
            'party_type' => $party->getMorphClass(),
            'party_id' => $party->getKey(),
        ]);
    }
}
