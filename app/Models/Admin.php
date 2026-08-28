<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Super Admin — the SaaS operator. Authenticates on the `admin` guard and is
 * NOT tenant-scoped (no BelongsToTenant). Manages businesses, plans, etc.
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /** @var list<string> */
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
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
