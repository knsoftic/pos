<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Services\InventoryService;
use Database\Factories\StockBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery of one product on one shelf, with its own expiry (#34).
 *
 * Tenant- and branch-scoped like {@see Stock}. Quantities are written only by
 * {@see InventoryService}, which keeps the batch total and the
 * shelf total moving together inside one transaction — a batch breakdown that
 * disagrees with the shelf figure would be worse than no breakdown at all.
 */
class StockBatch extends Model
{
    /** @use HasFactory<StockBatchFactory> */
    use BelongsToBranch, BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'branch_id',
        'product_id',
        'product_variant_id',
        'batch_number',
        'expiry_date',
        'unit_cost',
        'received_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'received_at' => 'datetime',
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

    /** Batches that still hold something. An emptied batch is history. */
    public function scopeRemaining(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    /**
     * FEFO order: first expiry, first out.
     *
     * Undated batches sort LAST, not first. A batch with no expiry is not
     * urgent, and putting it at the front would hold back stock that genuinely
     * needs to move.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
    }

    /** Still good, but not for long. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());
    }

    // --------------------------------------------------------------- helpers

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isBefore(now()->startOfDay());
    }

    /** Negative once expired, so callers can sort by urgency without branching. */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }

    public function isExpiringSoon(?int $withinDays = null): bool
    {
        $days = $this->daysUntilExpiry();
        $window = $withinDays ?? (int) config('inventory.expiry_warning_days', 30);

        return $days !== null && $days >= 0 && $days <= $window;
    }

    public function value(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 2);
    }

    public function label(): string
    {
        $name = $this->product?->name ?? 'Unknown product';
        $name = $this->variant === null ? $name : $name.' — '.$this->variant->name;

        return $this->batch_number === null ? $name : $name.' · '.$this->batch_number;
    }

    public function statusBadgeClass(): string
    {
        return match (true) {
            $this->isExpired() => 'badge-red',
            $this->isExpiringSoon() => 'badge-amber',
            default => 'badge-green',
        };
    }

    public function statusLabel(): string
    {
        $days = $this->daysUntilExpiry();

        return match (true) {
            $days === null => 'No expiry',
            $days < 0 => 'Expired '.abs($days).'d ago',
            $days === 0 => 'Expires today',
            $this->isExpiringSoon() => 'Expires in '.$days.'d',
            default => 'Good',
        };
    }
}
