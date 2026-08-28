<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One sellable variant of a variable product (#25).
 *
 * No Blameable: a variant is never edited on its own, only as part of saving its
 * product, so the product's own created_by/updated_by already answers "who".
 *
 * Like the product, `sku` and `barcode` are guarded — ProductService allocates
 * them so the two tables can share one code namespace safely.
 *
 * The option set is `options`, NOT `attributes`: `$attributes` is Eloquent's own
 * internal property, so a column by that name works from outside the model and
 * silently returns the raw attribute bag from inside it.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'name',
        'options',
        'cost_price',
        'selling_price',
        'alert_quantity',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'alert_quantity' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * "Red / Large" from `{"Colour": "Red", "Size": "Large"}`.
     *
     * Built here so the stored `name` and what the screen shows can never drift:
     * the service calls this on every save.
     *
     * @param  array<string, string>  $options
     */
    public static function nameFromOptions(array $options): string
    {
        $parts = array_values(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : null,
            $options,
        )));

        return $parts === [] ? 'Default' : implode(' / ', $parts);
    }

    /** "Colour: Red · Size: Large" — the long form, for a detail screen. */
    public function optionSummary(): string
    {
        $options = is_array($this->options) ? $this->options : [];

        if ($options === []) {
            return '';
        }

        $parts = [];

        foreach ($options as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        return implode(' · ', $parts);
    }

    public function marginPercent(): ?float
    {
        $selling = (float) $this->selling_price;

        if ($selling <= 0.0) {
            return null;
        }

        return round((($selling - (float) $this->cost_price) / $selling) * 100, 2);
    }
}
