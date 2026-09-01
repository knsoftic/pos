<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ProtectsFinancialRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for a subscription (#82). Tenant-scoped so a business can see
 * its own billing history and nobody else's.
 *
 * ⚠️ FINANCIAL RECORD — corrected by adding a `refunded` row, never by deleting
 * or editing history. #133 / #198
 */
class SubscriptionPayment extends Model
{
    use BelongsToTenant, ProtectsFinancialRecords;

    /**
     * SECURITY: `business_id` and `subscription_id` are set by the service layer,
     * not mass-assigned, so a payment cannot be attached to another tenant's
     * subscription through request input. #132
     *
     * @var list<string>
     */
    protected $fillable = [
        'amount',
        'currency',
        'method',
        'status',
        'reference',
        'paid_at',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Paid);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('paid_at')->orderByDesc('id');
    }

    public function formattedAmount(): string
    {
        return ($this->currency === config('subscription.currency')
                ? config('subscription.currency_symbol')
                : $this->currency.' ')
            .number_format((float) $this->amount, (int) config('subscription.currency_decimals'));
    }

    /** "bank_transfer" → "Bank Transfer". Methods are config-driven (#190). */
    public function methodLabel(): string
    {
        return str($this->method)->replace('_', ' ')->title()->toString();
    }

    /**
     * What a shop actually paid us. Ours to keep, and theirs to be shown.
     */
    public function isDeletableRecord(): bool
    {
        return false;
    }
}
