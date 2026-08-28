<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A unit of measure (#26), structured for conversion (#158).
 *
 * Stock is always held in the BASE unit. A derived unit only ever describes how
 * many base units it is worth, so converting is one multiplication and there is
 * no second quantity to keep in step.
 */
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'short_name',
        'base_unit_id',
        'conversion_factor',
        'allows_decimals',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6',
            'allows_decimals' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------- relations

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }

    public function derivedUnits(): HasMany
    {
        return $this->hasMany(self::class, 'base_unit_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBaseUnits(Builder $query): Builder
    {
        return $query->whereNull('base_unit_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    // --------------------------------------------------------------- helpers

    public function isBaseUnit(): bool
    {
        return $this->base_unit_id === null;
    }

    /** How many base units one of this unit is worth. A base unit is worth 1. */
    public function factor(): float
    {
        return $this->isBaseUnit() ? 1.0 : (float) $this->conversion_factor;
    }

    /** Convert a quantity expressed in THIS unit into base units. */
    public function toBase(float $quantity): float
    {
        return $quantity * $this->factor();
    }

    /** Convert a base-unit quantity into this unit. */
    public function fromBase(float $baseQuantity): float
    {
        $factor = $this->factor();

        return $factor === 0.0 ? 0.0 : $baseQuantity / $factor;
    }

    public function label(): string
    {
        return $this->name.' ('.$this->short_name.')';
    }

    public function isInUse(): bool
    {
        return $this->products()->withoutGlobalScope(SoftDeletingScope::class)->exists()
            || $this->derivedUnits()->withoutGlobalScope(SoftDeletingScope::class)->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isInUse();
    }
}
