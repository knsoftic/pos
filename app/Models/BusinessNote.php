<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private internal support note about a tenant (#159).
 *
 * ⚠️ OPERATOR-ONLY, BY DESIGN. This model carries NO tenant trait — not because
 * it lacks a business_id, but so that it can never be pulled into a
 * tenant-scoped query and leak an operator's private remarks to the customer
 * they are about. Only /admin controllers may touch it.
 */
class BusinessNote extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'admin_id',
        'admin_name',
        'body',
        'is_pinned',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The author. `admin_name` is stored alongside so a note written by a
     * since-removed operator still shows who wrote it.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('id');
    }

    public function authorName(): string
    {
        return $this->admin?->name ?? $this->admin_name ?? 'Unknown operator';
    }
}
