<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, BelongsToTenant;

    /**
     * Mass-assignable attributes.
     *
     * SECURITY: `business_id` is intentionally NOT fillable — a user must never
     * be able to set or move their own tenant. It is stamped by BelongsToTenant
     * (from the active context) or set explicitly by trusted server code. The
     * privilege flags `is_active` / `is_business_owner` are likewise guarded and
     * set only by server code.
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
        ];
    }

    // `business()` relation + tenant scoping are provided by BelongsToTenant.
}
