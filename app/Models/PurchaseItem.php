<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PurchaseItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line on a purchase (#35).
 *
 * The money methods here are the single definition of what a line is worth, so
 * the document total, the amount posted to the supplier's account and the value
 * of a partial receipt can never disagree — they all come through the same three
 * methods.
 */
class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }

    // ------------------------------------------------------------- relations

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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
        return $this->hasMany(PurchaseReturnItem::class);
    }

    // ----------------------------------------------------------------- money

    /** Quantity × cost, before the line's own discount and tax. */
    public function gross(): float
    {
        return round((float) $this->quantity_ordered * (float) $this->unit_cost, 2);
    }

    /** What the line comes to: gross, less its discount, plus its tax. */
    public function net(): float
    {
        $afterDiscount = max(0, $this->gross() - (float) $this->discount_amount);

        return round($afterDiscount * (1 + ((float) $this->tax_rate / 100)), 2);
    }

    public function taxAmount(): float
    {
        $afterDiscount = max(0, $this->gross() - (float) $this->discount_amount);

        return round($afterDiscount * ((float) $this->tax_rate / 100), 2);
    }

    /**
     * What one unit of this line is worth, all-in.
     *
     * The discount is spread across the ordered quantity so a partial delivery
     * carries its fair share of it — otherwise the first box through the door
     * would absorb the whole discount and the last one none of it.
     */
    public function effectiveUnitCost(): float
    {
        $quantity = (float) $this->quantity_ordered;

        if ($quantity <= 0) {
            return 0.0;
        }

        return round($this->net() / $quantity, 4);
    }

    /** The value of what has actually arrived on this line. */
    public function receivedValue(): float
    {
        return round((float) $this->quantity_received * $this->effectiveUnitCost(), 2);
    }

    // --------------------------------------------------------------- helpers

    /** Still to come on this line. Never negative — an over-delivery is not a debt. */
    public function outstanding(): float
    {
        return round(max(0, (float) $this->quantity_ordered - (float) $this->quantity_received), 4);
    }

    public function isFullyReceived(): bool
    {
        return $this->outstanding() <= 0;
    }

    /** How much of what arrived has already gone back (#37). */
    public function returnedQuantity(): float
    {
        return round((float) $this->returnItems()->sum('quantity'), 4);
    }

    /** The most that could still be sent back: received, less already returned. */
    public function returnableQuantity(): float
    {
        return round(max(0, (float) $this->quantity_received - $this->returnedQuantity()), 4);
    }

    public function label(): string
    {
        return $this->description;
    }
}
