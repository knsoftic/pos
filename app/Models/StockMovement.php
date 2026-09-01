<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ProtectsFinancialRecords;
use App\Services\InventoryService;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable line in the inventory ledger (#30).
 *
 * `$timestamps = false` with a `created_at` default in the migration: there is
 * no updated_at because a movement is never edited. A mistake is corrected by
 * posting the opposite movement, so the history of what the shop believed, and
 * when, stays intact (#133, #198).
 *
 * Written only by {@see InventoryService}, which is why nothing
 * here is fillable in a useful way — the service sets every column explicitly
 * inside a locked transaction.
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use BelongsToBranch, BelongsToTenant, HasFactory, ProtectsFinancialRecords;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------- relations

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The sale, purchase or transfer that caused this. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    // ---------------------------------------------------------------- scopes

    public function scopeNewestFirst(Builder $query): Builder
    {
        // By id, not created_at: two movements in the same transaction can share
        // a timestamp to the second, and the ledger must still read in the order
        // things actually happened.
        return $query->orderByDesc('id');
    }

    /** The exact shelf this movement belongs to. */
    public function scopeForShelf(Builder $query, int $productId, ?int $variantId = null): Builder
    {
        return $query->where('product_id', $productId)
            ->when($variantId === null,
                fn (Builder $q) => $q->whereNull('product_variant_id'),
                fn (Builder $q) => $q->where('product_variant_id', $variantId),
            );
    }

    // --------------------------------------------------------------- helpers

    public function isIncoming(): bool
    {
        return (float) $this->quantity > 0;
    }

    /** "+12" / "−3", already signed for display. */
    public function signedQuantity(): string
    {
        $quantity = (float) $this->quantity;
        $formatted = rtrim(rtrim(number_format(abs($quantity), 4, '.', ','), '0'), '.');

        return ($quantity >= 0 ? '+' : '−').$formatted;
    }

    /** The value this movement added to or took off the shelf. */
    public function value(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 2);
    }

    /**
     * ⚠️ THE LEDGER (#30). Stock is not a number, it is the sum of these lines —
     * delete one and every quantity after it becomes a fiction that
     * `pos:check-integrity` will report and nobody will be able to explain.
     * A mistake is corrected by posting the opposite movement.
     */
    public function isDeletableRecord(): bool
    {
        return false;
    }
}
