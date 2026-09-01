<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PurchaseReturnFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods sent back to a supplier (#37).
 *
 * No soft deletes and no status: a return is posted the moment it is created —
 * stock off the shelf, supplier credited — and a financial record that has been
 * acted on is never deleted (#133, #198). A return made in error is corrected by
 * a fresh purchase, the same way a stock mistake is corrected by an opposite
 * movement.
 */
class PurchaseReturn extends Model
{
    /** @use HasFactory<PurchaseReturnFactory> */
    use BelongsToBranch, BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('return_date')->orderByDesc('id');
    }

    public function totalQuantity(): float
    {
        return round((float) $this->items->sum('quantity'), 4);
    }
}
