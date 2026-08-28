<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Services\InventoryService;
use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The running balance for one product (or variant) on one branch's shelf
 * (#28, #136).
 *
 * Tenant-scoped AND branch-scoped: a cashier at one shop reads their own shelf
 * and nobody else's, without a single `where` in any controller (#48).
 *
 * Read-only from the outside. Nothing but {@see InventoryService}
 * may write these numbers, which is why `quantity` and `average_cost` are not
 * fillable: a form that could set a stock figure directly would bypass the
 * ledger, and the ledger is the truth.
 */
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use BelongsToBranch, BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'branch_id',
        'product_id',
        'product_variant_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'last_movement_at' => 'datetime',
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

    // ---------------------------------------------------------------- scopes

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('quantity', '<=', 0);
    }

    /**
     * Shelves at or below their warning threshold (#33).
     *
     * The threshold is the variant's, else the product's, else the system
     * fallback — resolved in SQL so a low-stock sweep is one query rather than
     * one per row. A shelf with no threshold anywhere is never "low": silence
     * is the correct answer for a product nobody asked to be warned about.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        $fallback = config('inventory.default_alert_quantity');

        return $query
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stocks.product_variant_id')
            ->where('products.track_inventory', true)
            ->whereRaw(
                'stocks.quantity <= COALESCE(product_variants.alert_quantity, products.alert_quantity, ?)',
                [$fallback ?? -1],
            )
            ->select('stocks.*');
    }

    // --------------------------------------------------------------- helpers

    /** What this shelf is worth at the cost currently on the books. */
    public function value(): float
    {
        return round((float) $this->quantity * (float) $this->average_cost, 2);
    }

    public function isOutOfStock(): bool
    {
        return (float) $this->quantity <= 0;
    }

    public function isNegative(): bool
    {
        return (float) $this->quantity < 0;
    }

    /** The threshold that applies to this shelf, or null when there is none. */
    public function alertQuantity(): ?float
    {
        $variantThreshold = $this->variant?->alert_quantity;
        $productThreshold = $this->product?->alert_quantity;
        $fallback = config('inventory.default_alert_quantity');

        $threshold = $variantThreshold ?? $productThreshold ?? $fallback;

        return $threshold === null ? null : (float) $threshold;
    }

    public function isLow(): bool
    {
        $threshold = $this->alertQuantity();

        return $threshold !== null && (float) $this->quantity <= $threshold;
    }

    /** What the row is called on screen: "Cotton T-Shirt — L / Black". */
    public function label(): string
    {
        $name = $this->product?->name ?? 'Unknown product';

        return $this->variant === null ? $name : $name.' — '.$this->variant->name;
    }

    public function statusBadgeClass(): string
    {
        return match (true) {
            $this->isNegative() => 'badge-red',
            $this->isOutOfStock() => 'badge-slate',
            $this->isLow() => 'badge-amber',
            default => 'badge-green',
        };
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isNegative() => 'Oversold',
            $this->isOutOfStock() => 'Out of stock',
            $this->isLow() => 'Low',
            default => 'In stock',
        };
    }
}
