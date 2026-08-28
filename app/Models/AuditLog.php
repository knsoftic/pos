<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable audit record (no updated_at). Written via AuditService (Phase 1+).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'actor_type',
        'actor_id',
        'event',
        'auditable_type',
        'auditable_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
