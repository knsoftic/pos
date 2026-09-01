<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ProtectsFinancialRecords;
use App\Services\SaleService;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale (#21). Tenant- and branch-scoped.
 *
 * NO SoftDeletes trait, on purpose: there is no route by which a sale can be
 * deleted, soft or otherwise. It is voided, and the record stays (#133, #198).
 *
 * Every figure on it is written by {@see SaleService} inside the
 * transaction that also moved the stock and the money, so nothing here is
 * fillable in a way that could make the document disagree with the ledgers.
 */
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use BelongsToBranch, BelongsToTenant, HasFactory, ProtectsFinancialRecords;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'sold_at' => 'datetime',
            'sale_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'rounding' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_total' => 'decimal:4',
            'paid_total' => 'decimal:2',
            'change_given' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'voided_at' => 'datetime',
            'print_count' => 'integer',
        ];
    }

    // ------------------------------------------------------------- relations

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(PosCounter::class, 'pos_counter_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** The stock this sale took off the shelf. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', $this->getMorphClass());
    }

    // ---------------------------------------------------------------- scopes

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('sold_at')->orderByDesc('id');
    }

    /** Sales that actually happened — held and voided ones are not takings. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::Completed);
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', SaleStatus::Held);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('sale_date', $date);
    }

    /** Sales with money still on the customer's account. */
    public function scopeOnCredit(Builder $query): Builder
    {
        return $query->where('due_amount', '>', 0);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('invoice_no', 'like', $like)
                ->orWhereHas('customer', fn (Builder $c) => $c
                    ->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like));
        });
    }

    // --------------------------------------------------------------- helpers

    public function isWalkIn(): bool
    {
        return $this->customer_id === null;
    }

    public function customerName(): string
    {
        return $this->customer?->name ?? 'Walk-in customer';
    }

    /**
     * What this sale made, at the cost that applied when it happened.
     *
     * ⚠️ Caller must have `reports.view_profit` before showing it (#52).
     */
    public function grossProfit(): float
    {
        return round((float) $this->subtotal - (float) $this->discount_total - (float) $this->cost_total, 2);
    }

    public function marginPercent(): ?float
    {
        $net = (float) $this->subtotal - (float) $this->discount_total;

        if ($net <= 0) {
            return null;
        }

        return round(($this->grossProfit() / $net) * 100, 2);
    }

    /** Cash actually taken, as opposed to charged to an account. */
    public function cashTaken(): float
    {
        $cashMethods = (array) config('pos.cash_methods', ['cash']);

        return round((float) $this->payments
            ->whereIn('method', $cashMethods)
            ->sum('amount'), 2);
    }

    public function isFullyPaid(): bool
    {
        return (float) $this->due_amount < 0.005;
    }

    public function paymentBadgeClass(): string
    {
        return match (true) {
            $this->status === SaleStatus::Voided => 'badge-red',
            $this->isFullyPaid() => 'badge-green',
            (float) $this->paid_total > 0 => 'badge-amber',
            default => 'badge-red',
        };
    }

    public function paymentLabel(): string
    {
        return match (true) {
            $this->status === SaleStatus::Voided => 'Voided',
            $this->isFullyPaid() => 'Paid',
            (float) $this->paid_total > 0 => 'Part paid',
            default => 'On account',
        };
    }

    /** The methods used, for a list that needs to say "cash + card" at a glance. */
    public function methodSummary(): string
    {
        $methods = $this->payments->pluck('method')->unique()
            ->map(fn (string $m) => str($m)->headline()->toString());

        return $methods->isEmpty() ? 'On account' : $methods->join(' + ');
    }

    public function totalQuantity(): float
    {
        return round((float) $this->items->sum('quantity'), 4);
    }

    // ------------------------------------------------------------- returns

    /** What has already come back against this sale (#53). */
    public function returnedValue(): float
    {
        return round((float) $this->returns()->sum('total'), 2);
    }

    public function hasReturns(): bool
    {
        return $this->returns()->exists();
    }

    /** Is there anything left that could still come back? */
    public function isFullyReturned(): bool
    {
        $this->loadMissing('items.returnItems');

        return $this->items->isNotEmpty()
            && $this->items->every(fn (SaleItem $item) => $item->returnableQuantity() <= 0);
    }

    /**
     * A partly returned sale must not be voided.
     *
     * Voiding reverses the WHOLE sale, and a return has already reversed part of
     * it — doing both would put the same goods back on the shelf twice and hand
     * the money back twice. The shop returns the rest instead.
     */
    public function canBeVoided(): bool
    {
        return $this->status->canBeVoided() && ! $this->hasReturns();
    }

    /**
     * ⚠️ HELD ONLY, and that distinction is the whole rule (#198).
     *
     * A held sale is a basket: it posted no stock, no ledger line and no
     * money, so abandoning one is correct and `pos:expire-holds` does it
     * nightly. A completed sale is a document somebody was handed — it is
     * voided, and the record stays. Same table, opposite answers.
     */
    public function isDeletableRecord(): bool
    {
        return $this->status->canBeDeleted();
    }
}
