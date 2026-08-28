<?php

namespace App\Models;

use App\Enums\TransferStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\StockTransferService;
use Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A movement of stock between two branches (#32).
 *
 * Tenant-scoped but deliberately NOT branch-scoped: a transfer has two ends, and
 * a branch-scoped global filter would have to pick one to filter on. The scoping
 * that matters is applied per question instead — {@see scopeVisibleTo()} — so a
 * manager sees transfers arriving at their shop as well as ones they sent.
 */
class StockTransfer extends Model
{
    /** @use HasFactory<StockTransferFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Everything that decides what has actually happened — the status, the
     * timestamps, the reference — is guarded. {@see StockTransferService}
     * sets those, because each one has to move in step with a ledger write.
     *
     * @var list<string>
     */
    protected $fillable = [
        'from_branch_id',
        'to_branch_id',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------- relations

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // ---------------------------------------------------------------- scopes

    /**
     * Transfers this user has any business seeing: ones leaving a branch they
     * can reach, or arriving at one.
     *
     * @param  list<int>|null  $branchIds  null = unrestricted (owner)
     */
    public function scopeVisibleTo(Builder $query, ?array $branchIds): Builder
    {
        if ($branchIds === null) {
            return $query;
        }

        // An empty list means the user has no branch at all, and the fail-closed
        // rule from BranchContext applies: they see nothing.
        return $query->where(function (Builder $q) use ($branchIds): void {
            $q->whereIn('from_branch_id', $branchIds)
                ->orWhereIn('to_branch_id', $branchIds);
        });
    }

    public function scopeStatus(Builder $query, TransferStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof TransferStatus ? $status->value : $status);
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    // --------------------------------------------------------------- helpers

    public function totalSent(): float
    {
        return round((float) $this->items->sum('quantity_sent'), 4);
    }

    /** NULL until someone has actually counted — which is not the same as zero. */
    public function totalReceived(): ?float
    {
        if ($this->status !== TransferStatus::Received) {
            return null;
        }

        return round((float) $this->items->sum('quantity_received'), 4);
    }

    /**
     * How much left but never arrived. Zero is the happy answer; anything else
     * is shown prominently rather than reconciled away.
     */
    public function shortfall(): float
    {
        return round($this->items->sum(
            fn (StockTransferItem $item) => $item->shortfall(),
        ), 4);
    }

    public function hasShortfall(): bool
    {
        return $this->shortfall() > 0.00005;
    }

    public function value(): float
    {
        return round($this->items->sum(
            fn (StockTransferItem $item) => (float) $item->quantity_sent * (float) $item->unit_cost,
        ), 2);
    }

    /** A draft that never moved anything is the only kind that can be removed. */
    public function canBeDeleted(): bool
    {
        return $this->status === TransferStatus::Draft;
    }
}
