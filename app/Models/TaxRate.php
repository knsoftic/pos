<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named tax rate the shop charges (#59).
 *
 * ⚠️ Products snapshot the NUMBER, not a reference to this row. Changing a rate
 * here changes what new lines get and deliberately does not restate a single
 * invoice already printed — see the migration for why.
 */
class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name', 'rate', 'is_default', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:3',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('rate');
    }

    /** "Standard — 17%" */
    public function label(): string
    {
        return sprintf('%s — %s%%', $this->name, rtrim(rtrim(number_format((float) $this->rate, 3), '0'), '.'));
    }
}
