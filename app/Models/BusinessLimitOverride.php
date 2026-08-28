<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-business quota override (#10). OPERATOR-OWNED, same rationale as
 * {@see BusinessFeatureOverride}: not tenant-scoped, because only the /admin
 * panel may read or write it.
 *
 * `value` NULL = unlimited. Missing row = inherit the plan. Audited (#177).
 */
class BusinessLimitOverride extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'limit_id',
        'value',
        'reason',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function limit(): BelongsTo
    {
        return $this->belongsTo(Limit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function isUnlimited(): bool
    {
        return $this->value === null;
    }
}
