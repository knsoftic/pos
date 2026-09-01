<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\ProtectsFinancialRecords;
use App\Services\PurchaseService;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A purchase from a supplier (#35, #36).
 *
 * Tenant- and branch-scoped: goods land on a particular shelf, so a manager at
 * one shop sees their own deliveries (#136, #48).
 *
 * Everything that says WHAT HAS HAPPENED — the status, the totals, the amount
 * paid, the timestamps — is guarded. {@see PurchaseService} owns
 * those, because each one has to move in step with a stock movement or a ledger
 * entry, and a form that could set them directly would be able to say a delivery
 * arrived without any stock arriving.
 */
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use BelongsToBranch, BelongsToTenant, Blameable, HasFactory, ProtectsFinancialRecords, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'branch_id',
        'supplier_id',
        'supplier_invoice_no',
        'order_date',
        'expected_date',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'ordered_at' => 'datetime',
            'first_received_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------- relations

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The stock this purchase put on the shelf. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', $this->getMorphClass());
    }

    // ---------------------------------------------------------------- scopes

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('order_date')->orderByDesc('id');
    }

    /** Orders with goods still to come. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PurchaseStatus::Draft->value,
            PurchaseStatus::Ordered->value,
            PurchaseStatus::Partial->value,
        ]);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereColumn('paid_amount', '<', 'total')
            ->whereIn('status', [PurchaseStatus::Partial->value, PurchaseStatus::Received->value]);
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
                ->orWhere('supplier_invoice_no', 'like', $like)
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', $like));
        });
    }

    // --------------------------------------------------------------- helpers

    /** What is still to arrive, in units, across every line. */
    public function outstandingQuantity(): float
    {
        return round($this->items->sum(fn (PurchaseItem $item) => $item->outstanding()), 4);
    }

    public function isFullyReceived(): bool
    {
        return $this->items->isNotEmpty() && $this->outstandingQuantity() <= 0;
    }

    /**
     * The value of what has actually arrived, at the prices on this document.
     *
     * This — not `total` — is what the supplier is owed: you owe for goods you
     * have, not for goods you asked for.
     */
    public function receivedValue(): float
    {
        return round($this->items->sum(fn (PurchaseItem $item) => $item->receivedValue()), 2);
    }

    /** Still to pay on what has arrived. */
    public function balanceDue(): float
    {
        return round(max(0, $this->receivedValue() - (float) $this->paid_amount), 2);
    }

    public function isSettled(): bool
    {
        return $this->balanceDue() < 0.005;
    }

    public function paymentBadgeClass(): string
    {
        return match (true) {
            ! $this->status->hasPosted() => 'badge-slate',
            $this->isSettled() => 'badge-green',
            (float) $this->paid_amount > 0 => 'badge-amber',
            default => 'badge-red',
        };
    }

    public function paymentLabel(): string
    {
        return match (true) {
            ! $this->status->hasPosted() => 'Nothing owed yet',
            $this->isSettled() => 'Paid',
            (float) $this->paid_amount > 0 => 'Part paid',
            default => 'Unpaid',
        };
    }

    /** How much of this delivery has already gone back to the supplier. */
    public function returnedValue(): float
    {
        return round((float) $this->returns()->sum('total'), 2);
    }

    public function canBeDeleted(): bool
    {
        return $this->status->canBeDeleted();
    }

    /**
     * Only an untouched DRAFT. Ordering posts nothing, but receiving moves stock
     * and the supplier's account, and once that has happened the document is
     * cancelled rather than removed.
     */
    public function isDeletableRecord(): bool
    {
        return $this->status->canBeDeleted();
    }
}
