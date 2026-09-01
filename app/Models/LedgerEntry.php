<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Models\Concerns\BelongsToTenant;
use App\Services\PartyLedgerService;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable line on a party's account (#41, #42).
 *
 * `$timestamps = false` with a `created_at` default: there is no `updated_at`
 * because a financial line is never edited. A mistake is corrected by posting
 * its opposite (#133, #198), which is the same rule the stock ledger follows.
 *
 * Written only by {@see PartyLedgerService}, inside a transaction
 * that also moves the party's cached balance — so the statement and the balance
 * cannot disagree.
 *
 * NOT branch-scoped: an account is business-wide (#137). `branch_id` says where
 * the entry happened, and nothing filters on it by default.
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use BelongsToTenant, HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'entry_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------- relations

    /** The customer or supplier this line belongs to. */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    /** The sale, purchase or payment behind it, when there is one. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------- scopes

    /**
     * Statement order: oldest first, because a running balance only reads
     * correctly downwards. By id within a date — two entries on the same day
     * must appear in the order they actually happened.
     */
    public function scopeStatementOrder(Builder $query): Builder
    {
        return $query->orderBy('entry_date')->orderBy('id');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('entry_date')->orderByDesc('id');
    }

    /** @param  Customer|Supplier  $party */
    public function scopeForParty(Builder $query, Model $party): Builder
    {
        return $query->where('party_type', $party->getMorphClass())
            ->where('party_id', $party->getKey());
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from !== null && $from !== '', fn (Builder $q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to !== null && $to !== '', fn (Builder $q) => $q->whereDate('entry_date', '<=', $to));
    }

    // --------------------------------------------------------------- helpers

    public function isDebit(): bool
    {
        return (float) $this->debit > 0;
    }

    /** The amount, whichever column it landed in. */
    public function amount(): float
    {
        return $this->isDebit() ? (float) $this->debit : (float) $this->credit;
    }

    /** What the line says it is, falling back to the type's own name. */
    public function title(): string
    {
        return $this->description ?: $this->type->label();
    }
}
