<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-business feature grant/revoke (#10). OPERATOR-OWNED — written only from
 * the /admin panel, so deliberately NOT tenant-scoped: the resolver reads it by
 * explicit business_id and a tenant request must never be able to create one.
 *
 * A missing row means "inherit from the plan"; going back to inheriting means
 * DELETING the row. Every write is audited (#177).
 */
class BusinessFeatureOverride extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'feature_id',
        'is_enabled',
        'reason',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
