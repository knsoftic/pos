<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\EmployeeService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable, SoftDeletes;

    /**
     * Mass-assignable attributes.
     *
     * SECURITY: `business_id` is intentionally NOT fillable — a user must never
     * be able to set or move their own tenant. It is stamped by BelongsToTenant
     * (from the active context) or set explicitly by trusted server code. The
     * privilege flags `is_active` / `is_business_owner` are likewise guarded, and
     * so is everything added in Phase 3: `role_id`, `branch_id`,
     * `pos_counter_id` and `max_discount_percent` decide what a person may do
     * and see, so they are only ever set through
     * {@see EmployeeService}, which checks each one against the
     * current tenant first.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_business_owner' => 'boolean',
            'last_login_at' => 'datetime',
            'max_discount_percent' => 'decimal:2',
        ];
    }

    // `business()` relation + tenant scoping are provided by BelongsToTenant.

    // ------------------------------------------------------------- relations

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function posCounter(): BelongsTo
    {
        return $this->belongsTo(PosCounter::class);
    }

    // --------------------------------------------------------------- helpers

    /**
     * The owner outranks the role system entirely (#51). Kept as a method so the
     * intent reads the same everywhere this is asked.
     */
    public function isOwner(): bool
    {
        return (bool) $this->is_business_owner;
    }

    /**
     * The discount ceiling that applies to this person, as a percentage.
     * NULL means no personal cap; 0 means no discounts at all (#141).
     */
    public function discountCap(): ?float
    {
        if ($this->isOwner()) {
            return null;
        }

        return $this->max_discount_percent === null
            ? null
            : (float) $this->max_discount_percent;
    }

    /** May this person approve a discount of the given percentage? (#141) */
    public function mayDiscount(float $percent): bool
    {
        $cap = $this->discountCap();

        return $cap === null || $percent <= $cap;
    }

    public function roleName(): string
    {
        if ($this->isOwner()) {
            return 'Owner';
        }

        return $this->role?->name ?? 'No role';
    }
}
