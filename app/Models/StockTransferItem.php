<?php

namespace App\Models;

use App\Models\Concerns\ProtectsFinancialRecords;
use Database\Factories\StockTransferItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on one transfer (#32).
 *
 * No tenant trait: an item is only ever reached through its {@see StockTransfer},
 * which is tenant-scoped. Adding a second scope here would mean a second place
 * that could be got wrong.
 */
class StockTransferItem extends Model
{
    /** @use HasFactory<StockTransferItemFactory> */
    use HasFactory, ProtectsFinancialRecords;

    /** `quantity_received` is set only by the receiving flow, never by a form fill. */
    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'product_variant_id',
        'quantity_sent',
        'unit_cost',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_sent' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** "Cotton T-Shirt — L / Black" */
    public function label(): string
    {
        $name = $this->product?->name ?? 'Unknown product';

        return $this->variant === null ? $name : $name.' — '.$this->variant->name;
    }

    /**
     * What left but never arrived. Only meaningful once counted, so an
     * un-received line reports zero rather than pretending everything is lost.
     */
    public function shortfall(): float
    {
        if ($this->quantity_received === null) {
            return 0.0;
        }

        return max(0.0, round((float) $this->quantity_sent - (float) $this->quantity_received, 4));
    }

    public function hasShortfall(): bool
    {
        return $this->shortfall() > 0.00005;
    }

    /** More arrived than was sent — rare, but it happens, and it is not a shortfall. */
    public function hasOverage(): bool
    {
        return $this->quantity_received !== null
            && (float) $this->quantity_received > (float) $this->quantity_sent + 0.00005;
    }

    /**
     * Deletable while its transfer is still a draft, which is what lets the
     * lines be replaced during editing.
     */
    public function isDeletableRecord(): bool
    {
        return $this->transfer?->canBeDeleted() ?? false;
    }
}
