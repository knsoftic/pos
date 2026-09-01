<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A message from the operator to every shop (#77).
 *
 * ⚠️ NOT tenant-scoped: one announcement is written once and read by everybody.
 * See the migration for why an announcement is a MESSAGE and an alert is a
 * CONDITION, and why only this one can be dismissed.
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title', 'body', 'level', 'is_active', 'starts_at', 'ends_at', 'is_dismissible', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_dismissible' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** Who has put it away. */
    public function dismissedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_dismissals')
            ->withPivot('dismissed_at');
    }

    /**
     * Live right now: switched on, started, and not finished.
     *
     * The dates are the point — "maintenance on Sunday" is worse than useless
     * on Monday, so the operator writes it in advance and never has to remember
     * to take it down.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function isLive(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function badgeClass(): string
    {
        return match ($this->level) {
            'danger' => 'badge-red',
            'warning' => 'badge-amber',
            default => 'badge-slate',
        };
    }
}
