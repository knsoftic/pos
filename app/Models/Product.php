<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Blameable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A catalogue item (#24, #25). Tenant-scoped, blameable, archivable.
 *
 * Note what is NOT here: stock. A product does not have "a quantity" — it has a
 * quantity PER BRANCH (#136), which lives in the inventory tables and is only
 * ever read through the inventory service (#185). Putting a cached total on
 * this row would create a second truth that drifts.
 *
 * `sku` and `barcode` are guarded: they are allocated by ProductService, which
 * owns uniqueness across products AND variants at once. A form that could set
 * them directly would be able to collide the two namespaces.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, Blameable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'category_id',
        'brand_id',
        'unit_id',
        'type',
        'name',
        'description',
        'image_path',
        'cost_price',
        'selling_price',
        'tax_rate',
        'track_inventory',
        'tracks_batches',
        'alert_quantity',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'alert_quantity' => 'decimal:4',
            'track_inventory' => 'boolean',
            'tracks_batches' => 'boolean',
            'is_active' => 'boolean',
            'is_favourite' => 'boolean',
        ];
    }

    // ------------------------------------------------------------- relations

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('name');
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Everything that can actually be counted — services never can (#25). */
    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('track_inventory', true)
            ->where('type', '!=', ProductType::Service->value);
    }

    /**
     * The search the POS and the catalogue screen both use: name, SKU, barcode.
     * Bound parameters throughout — never string-interpolated (#135).
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like, $term): void {
            $q->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('barcode', $term)
                ->orWhereHas('variants', fn (Builder $v) => $v
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', $term));
        });
    }

    // --------------------------------------------------------------- helpers

    public function hasVariants(): bool
    {
        return $this->type->hasVariants();
    }

    /**
     * Does this row take part in stock at all? Both conditions matter: a service
     * never does, and a physical item may be opted out (a free carrier bag).
     */
    public function tracksStock(): bool
    {
        return $this->type->tracksStock() && $this->track_inventory;
    }

    /**
     * The price a customer pays. For a variable product there is no single
     * answer, so the caller is told the range instead — see priceRange().
     */
    public function price(): float
    {
        return (float) $this->selling_price;
    }

    /** @return array{min: float, max: float} */
    public function priceRange(): array
    {
        if (! $this->hasVariants()) {
            return ['min' => $this->price(), 'max' => $this->price()];
        }

        $prices = $this->variants->pluck('selling_price')->map(fn ($p) => (float) $p);

        return [
            'min' => (float) ($prices->min() ?? 0),
            'max' => (float) ($prices->max() ?? 0),
        ];
    }

    /**
     * Margin as a percentage of the selling price. Returns null when there is
     * nothing meaningful to divide by, rather than a misleading zero.
     *
     * ⚠️ Caller must have `products.view_cost` (#52) before showing this.
     */
    public function marginPercent(): ?float
    {
        $selling = (float) $this->selling_price;
        $cost = (float) $this->cost_price;

        if ($selling <= 0.0) {
            return null;
        }

        return round((($selling - $cost) / $selling) * 100, 2);
    }

    /**
     * A product that has ever been bought or sold is archived, not deleted
     * (#104, #198). Those tables arrive in Phases 6 and 7; until then only the
     * variants can hold it back, and this method is the single place the rule
     * will grow.
     */
    public function isInUse(): bool
    {
        return false;
    }

    public function canBeDeleted(): bool
    {
        return ! $this->isInUse();
    }

    /** Variants live and die with their product, so they go too. */
    public function variantsIncludingTrashed(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->withoutGlobalScope(SoftDeletingScope::class);
    }
}
