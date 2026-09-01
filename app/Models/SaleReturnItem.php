<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ProtectsFinancialRecords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sale return (#53).
 *
 * `restock` decides whether these goods go back on the shelf. See the migration
 * for why that is per line and not per document.
 */
class SaleReturnItem extends Model
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
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'restock' => 'boolean',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * What the customer gets back for this line.
     *
     * ⚠️ `unit_price` here is the sale line's ALL-IN price — discount already
     * spread, tax already in it (see SaleItem::effectiveUnitPrice). Applying
     * `tax_rate` again would hand back the tax twice. The rate is stored for the
     * record, not to be re-applied.
     */
    public function net(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    public function costValue(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 4);
    }

    /**
     * A line of a posted return. It moved stock or wrote it off; either way the
     * movement exists and this is what explains it.
     */
    public function isDeletableRecord(): bool
    {
        return false;
    }
}
