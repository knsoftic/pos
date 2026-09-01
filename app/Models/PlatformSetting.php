<?php

namespace App\Models;

use App\Services\PlatformSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the operator has changed from the shipped default (#110).
 *
 * ⚠️ NOT tenant-scoped, and deliberately so: there is one of each for the whole
 * installation. Read and written through {@see PlatformSettingsService}.
 */
class PlatformSetting extends Model
{
    /** @var list<string> */
    protected $fillable = ['key', 'value', 'updated_by'];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }
}
