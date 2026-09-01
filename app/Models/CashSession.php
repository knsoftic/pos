<?php

namespace App\Models;

use App\Enums\CashSessionStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CashSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A till's trading period (#46, #139).
 *
 * The figures here answer one question: should this drawer balance? Expected
 * cash is the float plus what came in less what went out; the difference against
 * what was counted is what a shop actually looks at.
 */
class CashSession extends Model
{
    /** @use HasFactory<CashSessionFactory> */
    use BelongsToBranch, BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'cash_refunds' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_out' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------- relations

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(PosCounter::class, 'pos_counter_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CashSessionStatus::Open);
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('opened_at')->orderByDesc('id');
    }

    // --------------------------------------------------------------- helpers

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    /**
     * What the drawer should hold right now.
     *
     * Computed rather than read from the column while the session is open, so
     * the figure on screen is always current; `expected_cash` is stamped at
     * close, when it has to stop moving.
     */
    public function expectedCash(): float
    {
        if ($this->expected_cash !== null && ! $this->isOpen()) {
            return (float) $this->expected_cash;
        }

        return round(
            (float) $this->opening_float
            + (float) $this->cash_sales
            + (float) $this->cash_in
            - (float) $this->cash_refunds
            - (float) $this->cash_out,
            2,
        );
    }

    /** Positive is over, negative is short. Null until it has been counted. */
    public function shortfall(): ?float
    {
        if ($this->counted_cash === null) {
            return null;
        }

        return round((float) $this->counted_cash - $this->expectedCash(), 2);
    }

    public function isBalanced(): bool
    {
        $shortfall = $this->shortfall();

        return $shortfall !== null && abs($shortfall) < 0.005;
    }

    public function differenceBadgeClass(): string
    {
        $shortfall = $this->shortfall();

        return match (true) {
            $shortfall === null => 'badge-slate',
            abs($shortfall) < 0.005 => 'badge-green',
            $shortfall > 0 => 'badge-amber',
            default => 'badge-red',
        };
    }

    public function differenceLabel(): string
    {
        $shortfall = $this->shortfall();

        return match (true) {
            $shortfall === null => 'Not counted',
            abs($shortfall) < 0.005 => 'Balanced',
            $shortfall > 0 => number_format($shortfall, 2).' over',
            default => number_format(abs($shortfall), 2).' short',
        };
    }

    public function duration(): string
    {
        $end = $this->closed_at ?? now();

        return $this->opened_at->diffForHumans($end, true);
    }
}
