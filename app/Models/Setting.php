<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a shop has changed from the shipped default (#57).
 *
 * Read and written through {@see SettingsService}, never
 * directly: the service is what validates against the registry, casts the
 * value and keeps the config overlay in step. A raw write here would produce a
 * setting the rest of the system reads but nothing ever checked.
 */
class Setting extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'updated_by'];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
