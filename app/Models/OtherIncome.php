<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\OtherIncomeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money in that was not a sale (#44). It sits BELOW the gross-profit line —
 * see the migration for why putting it in revenue would wreck the margin.
 */
class OtherIncome extends Model
{
    /** @use HasFactory<OtherIncomeFactory> */
    use BelongsToBranch, BelongsToTenant, Blameable, HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'income_date' => 'date',
            'amount' => 'decimal:2',
            'attachment_size' => 'integer',
        ];
    }

    // ------------------------------------------------------------- relations

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
        return $query->orderByDesc('income_date')->orderByDesc('id');
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('income_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('income_date', '<=', $to));
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('reference', 'like', $like)
                ->orWhere('source', 'like', $like)
                ->orWhere('note', 'like', $like);
        });
    }

    // --------------------------------------------------------------- helpers

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

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

    public function isCash(): bool
    {
        return in_array((string) $this->payment_method, (array) config('pos.cash_methods', ['cash']), true);
    }
}
