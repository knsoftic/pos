<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ProtectsFinancialRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tender against a sale (#17, #19).
 *
 * A sale can have several: half on a card and the rest in cash is ordinary, and
 * each has to be reconcilable separately at the end of the day.
 */
class SalePayment extends Model
{
    use BelongsToTenant, HasFactory, ProtectsFinancialRecords;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Tenders that settle into the drawer, and so count at cash-up (#46). */
    public function scopeCash(Builder $query): Builder
    {
        return $query->whereIn('method', (array) config('pos.cash_methods', ['cash']));
    }

    /** The one "payment" that takes no money — it charges an account (#40). */
    public function isCredit(): bool
    {
        return $this->method === config('pos.credit_method', 'credit');
    }

    public function isCash(): bool
    {
        return in_array($this->method, (array) config('pos.cash_methods', ['cash']), true);
    }

    public function label(): string
    {
        return str($this->method)->headline()->toString();
    }

    /**
     * Money that changed hands. Never deleted: the drawer already counted it,
     * and a payment that vanishes makes the sale it settled unpayable.
     */
    public function isDeletableRecord(): bool
    {
        return false;
    }
}
