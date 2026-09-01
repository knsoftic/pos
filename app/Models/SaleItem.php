<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a sale (#14).
 *
 * The money methods here are the single definition of what a line is worth, so
 * the receipt, the totals and the profit figure can never disagree — the same
 * arrangement as PurchaseItem, and for the same reason.
 */
class SaleItem extends Model
{
    use BelongsToTenant, HasFactory;

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
}
