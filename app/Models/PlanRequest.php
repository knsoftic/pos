<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\PlanRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop asking to move onto a different plan (#82).
 *
 * ⚠️ TENANT-SCOPED, UNLIKE {@see BusinessNote}, and the difference matters. A
 * support note is written ABOUT a shop and must never be visible TO it. This is
 * written BY the shop, and the shop is entitled to see what it asked for and
 * whether anyone has dealt with it. So the trait stays on, /app sees only its
 * own rows, and the operator's screens reach across with `allTenants()`.
 */
class PlanRequest extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'plan_id',
        'billing_cycle',
        'user_id',
        'requested_by_name',
        'current_plan_name',
        'status',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlanRequestStatus::class,
            'billing_cycle' => BillingCycle::class,
            'handled_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------- relations

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by');
    }

    // ---------------------------------------------------------------- scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PlanRequestStatus::Pending);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
