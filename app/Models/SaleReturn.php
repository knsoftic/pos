<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ProtectsFinancialRecords;
use Database\Factories\SaleReturnFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods returned by a customer (#53).
 *
 * No status and no soft deletes: a return is posted the moment it is created —
 * stock back (where the goods are fit to sell), money back or credited — and a
 * financial record that has been acted on is never deleted (#198). A return made
 * in error is corrected by selling the item again, the same way a stock mistake
 * is corrected by an opposite movement.
 */
class SaleReturn extends Model
{
    /** @use HasFactory<SaleReturnFactory> */
    use BelongsToBranch, BelongsToTenant, HasFactory, ProtectsFinancialRecords;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_total' => 'decimal:4',
            'refunded_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------- relations

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('return_date')->orderByDesc('id');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('reference', 'like', $like)
                ->orWhere('reason', 'like', $like)
                ->orWhereHas('sale', fn (Builder $s) => $s->where('invoice_no', 'like', $like))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like));
        });
    }

    // --------------------------------------------------------------- helpers

    public function customerName(): string
    {
        return $this->customer?->name ?? 'Walk-in customer';
    }

    public function totalQuantity(): float
    {
        return round((float) $this->items->sum('quantity'), 4);
    }

    /** How much of what came back is fit to sell again. */
    public function restockedQuantity(): float
    {
        return round((float) $this->items->where('restock', true)->sum('quantity'), 4);
    }

    public function writtenOffQuantity(): float
    {
        return round((float) $this->items->where('restock', false)->sum('quantity'), 4);
    }

    /**
     * The cost that came BACK into stock — restocked lines only.
     *
     * ⚠️ This, not `cost_total`, is what reverses out of cost of goods sold. A
     * written-off line never returned to the shelf, so its cost stays spent:
     * the shop paid for those goods and no longer has them. Treating the whole
     * `cost_total` as recovered would credit the business with inventory it
     * does not own.
     */
    public function restockedCost(): float
    {
        return round(
            (float) $this->items->where('restock', true)->sum(fn ($item) => $item->costValue()),
            4,
        );
    }

    /** The cost of what came back damaged and was thrown away. */
    public function writtenOffCost(): float
    {
        return round((float) $this->cost_total - $this->restockedCost(), 4);
    }

    /**
     * The profit this return took back.
     *
     * Revenue reverses in full — the customer has their money. Cost reverses
     * only for what went back on the shelf, so a fully written-off return costs
     * the shop the whole sale value, which is exactly what happened.
     *
     * ⚠️ Needs `reports.view_profit` before it goes on a screen (#52).
     */
    public function profitReversed(): float
    {
        return round((float) $this->subtotal - $this->restockedCost(), 2);
    }

    /** How the money went back, in words a receipt can print. */
    public function settlementLabel(): string
    {
        $parts = [];

        if ((float) $this->refunded_amount > 0) {
            $parts[] = number_format((float) $this->refunded_amount, 2).' refunded'
                .($this->refund_method ? ' ('.str($this->refund_method)->headline().')' : '');
        }

        if ((float) $this->credited_amount > 0) {
            $parts[] = number_format((float) $this->credited_amount, 2).' credited to the account';
        }

        return $parts === [] ? 'Nothing settled' : implode(' · ', $parts);
    }

    /**
     * A return is its own document with its own date and reason (#198). Deleting
     * one would silently restate the sale it refunded.
     */
    public function isDeletableRecord(): bool
    {
        return false;
    }
}
