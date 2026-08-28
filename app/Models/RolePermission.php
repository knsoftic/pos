<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One granted permission code on one role.
 *
 * No timestamps and no tenant trait: rows are reached only through a
 * {@see Role}, which is itself tenant-scoped, and they are rewritten wholesale
 * whenever the role is saved — so there is nothing to date.
 */
class RolePermission extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'role_id',
        'permission',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
