<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money spent that is not stock (#43). See the migration for why that
 * distinction is the one the whole P&L rests on.
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use BelongsToBranch, BelongsToTenant, Blameable, HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'attachment_size' => 'integer',
        ];
    }

    // ------------------------------------------------------------- relations

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('expense_date')->orderByDesc('id');
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('expense_date', '<=', $to));
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('reference', 'like', $like)
                ->orWhere('payee', 'like', $like)
                ->orWhere('bill_no', 'like', $like)
                ->orWhere('note', 'like', $like)
                ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', $like));
        });
    }

    // --------------------------------------------------------------- helpers

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

    /** "412 KB" — for a link that tells you what you are about to open. */
    public function attachmentSizeForHumans(): string
    {
        $bytes = (int) $this->attachment_size;

        if ($bytes <= 0) {
            return '';
        }

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format(max(1, $bytes / 1024)).' KB';
    }

    /**
     * Whether this expense took money out of a till, as opposed to a bank
     * account. Only cash needs a drawer to be told about it.
     */
    public function isCash(): bool
    {
        return in_array((string) $this->payment_method, (array) config('pos.cash_methods', ['cash']), true);
    }
}
