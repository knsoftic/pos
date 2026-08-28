<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One plan × one billing cycle × one price (#175). Operator-owned.
 */
class PlanPrice extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'billing_cycle',
        'price',
        'custom_days',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'price' => 'decimal:2',
            'custom_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    /**
     * Money formatted in the operator's billing currency. Symbol and decimal
     * count come from config, never hardcoded. #190
     */
    public function formatted(): string
    {
        return config('subscription.currency_symbol')
            .number_format((float) $this->price, (int) config('subscription.currency_decimals'));
    }

    /** e.g. "$49.00 /mo", or "$0.00 one-time" for a lifetime freebie. */
    public function label(): string
    {
        return $this->formatted().' '.$this->periodLabel();
    }

    public function periodLabel(): string
    {
        if ($this->billing_cycle === BillingCycle::Custom) {
            return '/'.$this->custom_days.' days';
        }

        return $this->billing_cycle->suffix();
    }

    /** Effective duration in days — only meaningful for the custom cycle. */
    public function days(): ?int
    {
        return $this->billing_cycle === BillingCycle::Custom ? $this->custom_days : null;
    }

    /**
     * Monthly-equivalent cost, so the UI can show "save 20% vs monthly".
     * Null when the cycle has no month count (lifetime / custom).
     */
    public function perMonth(): ?float
    {
        $months = $this->billing_cycle->months();

        return $months === null ? null : round((float) $this->price / $months, 2);
    }
}
