<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasLedger;
use App\Services\CustomerLedgerService;
use App\Services\CustomerService;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone the business sells to (#39). Tenant-scoped, business-level (#137).
 *
 * `balance` positive = THEY OWE THE BUSINESS. Not fillable: it is a cache over
 * the ledger, written only by {@see CustomerLedgerService}.
 * `code` is allocated by {@see CustomerService} so it stays unique.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, Blameable, HasFactory, HasLedger, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'city',
        'tax_number',
        'credit_limit',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOwing(Builder $query): Builder
    {
        return $query->where('balance', '>', 0);
    }

    /** Name, code, phone — what a cashier types at the till. */
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
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    // --------------------------------------------------------------- helpers

    /** What this customer owes right now. Never negative — see availableCredit(). */
    public function owesUs(): float
    {
        return max(0, round((float) $this->balance, 2));
    }

    /** Money held on their account, e.g. after a refund. */
    public function inCredit(): float
    {
        return max(0, round(-1 * (float) $this->balance, 2));
    }

    public function isBlocked(): bool
    {
        return ! $this->is_active;
    }

    /**
     * NULL means no ceiling; 0 means cash only. Kept as a method so the
     * three-value convention is read the same way everywhere (#40).
     */
    public function creditLimit(): ?float
    {
        return $this->credit_limit === null ? null : (float) $this->credit_limit;
    }

    public function hasUnlimitedCredit(): bool
    {
        return $this->credit_limit === null;
    }

    /** How much more they may run up. NULL = no ceiling. */
    public function availableCredit(): ?float
    {
        $limit = $this->creditLimit();

        if ($limit === null) {
            return null;
        }

        return max(0, round($limit - $this->owesUs(), 2));
    }

    /**
     * May this customer take on `$amount` more of debt?
     *
     * A blocked customer never may, whatever their limit says — that is the
     * whole point of blocking them (#105).
     */
    public function canTakeCredit(float $amount): bool
    {
        if ($this->isBlocked()) {
            return false;
        }

        if ($amount <= 0) {
            return true;
        }

        $available = $this->availableCredit();

        return $available === null || $amount <= $available;
    }

    public function isOverLimit(): bool
    {
        $limit = $this->creditLimit();

        return $limit !== null && $this->owesUs() > $limit;
    }

    /**
     * A customer with ledger history is archived, not deleted (#104) — deleting
     * one would take their statement with it.
     */
    public function isInUse(): bool
    {
        return $this->ledgerEntries()->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isInUse();
    }
}
