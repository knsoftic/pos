<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ProtectsFinancialRecords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line on a sale (#14).
 *
 * The money methods here are the single definition of what a line is worth, so
 * the receipt, the totals and the profit figure can never disagree — the same
 * arrangement as PurchaseItem, and for the same reason.
 */
class SaleItem extends Model
{
    use BelongsToTenant, HasFactory, ProtectsFinancialRecords;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    // ----------------------------------------------------------------- money

    /** Quantity × price, before this line's discount and tax. */
    public function gross(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    public function taxAmount(): float
    {
        $afterDiscount = max(0, $this->gross() - (float) $this->discount_amount);

        return round($afterDiscount * ((float) $this->tax_rate / 100), 2);
    }

    /** What the customer pays for this line. */
    public function net(): float
    {
        $afterDiscount = max(0, $this->gross() - (float) $this->discount_amount);

        return round($afterDiscount * (1 + ((float) $this->tax_rate / 100)), 2);
    }

    /** What these goods cost the shop, at the cost that applied when sold (#52). */
    public function costValue(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 4);
    }

    /** ⚠️ Needs `reports.view_profit` before it goes on a screen (#52). */
    public function grossProfit(): float
    {
        return round(($this->gross() - (float) $this->discount_amount) - $this->costValue(), 2);
    }

    /** The discount as a percentage, for a receipt that wants to show it that way. */
    public function discountPercent(): float
    {
        $gross = $this->gross();

        return $gross <= 0 ? 0.0 : round(((float) $this->discount_amount / $gross) * 100, 2);
    }

    // --------------------------------------------------------------- returns

    /** How much of this line has already come back (#53). */
    public function returnedQuantity(): float
    {
        return round((float) $this->returnItems()->sum('quantity'), 4);
    }

    /** The most that could still come back: sold, less already returned. */
    public function returnableQuantity(): float
    {
        return round(max(0, (float) $this->quantity - $this->returnedQuantity()), 4);
    }

    /**
     * What one unit of this line is worth to the customer, all in.
     *
     * The line discount is spread across the quantity sold, so returning half of
     * a discounted line gives back half of the discounted price — not the full
     * one, which would hand back more money than was taken.
     */
    public function effectiveUnitPrice(): float
    {
        $quantity = (float) $this->quantity;

        return $quantity <= 0 ? 0.0 : round($this->net() / $quantity, 4);
    }

    /**
     * A line lives and dies with its sale: deletable exactly while the sale is
     * still a held basket. A missing parent answers NO — an orphan line is a
     * puzzle, and quietly erasing puzzles is how ledgers stop reconciling.
     */
    public function isDeletableRecord(): bool
    {
        return $this->sale?->status->canBeDeleted() ?? false;
    }
}
