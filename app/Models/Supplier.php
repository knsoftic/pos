<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasLedger;
use App\Services\SupplierLedgerService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone the business buys from (#38). Tenant-scoped, business-level (#137).
 *
 * `balance` positive = THE BUSINESS OWES THEM — the mirror of a customer's, with
 * the same arithmetic behind it. Not fillable: it is a cache over the ledger,
 * written only by {@see SupplierLedgerService}.
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use BelongsToTenant, Blameable, HasFactory, HasLedger, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'city',
        'tax_number',
        'payment_terms_days',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Suppliers the business still owes money to. */
    public function scopeOwed(Builder $query): Builder
    {
        return $query->where('balance', '>', 0);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('contact_person', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    // --------------------------------------------------------------- helpers

    /** What the business owes this supplier right now. */
    public function weOwe(): float
    {
        return max(0, round((float) $this->balance, 2));
    }

    /** Money sitting with them in the business's favour — an advance, a credit note. */
    public function theyOweUs(): float
    {
        return max(0, round(-1 * (float) $this->balance, 2));
    }

    public function isBlocked(): bool
    {
        return ! $this->is_active;
    }

    /** When a bill dated today would fall due, if terms are set. */
    public function dueDateFrom(\DateTimeInterface $date): ?CarbonInterface
    {
        if ($this->payment_terms_days === null) {
            return null;
        }

        return Carbon::instance($date)->copy()->addDays($this->payment_terms_days);
    }

    public function isInUse(): bool
    {
        return $this->ledgerEntries()->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isInUse();
    }
}
