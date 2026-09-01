<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\LimitExceededException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The catalogue's write path (#24, #25, #27).
 *
 * Three things live here because nothing else can own them safely:
 *
 *  1. CODE ALLOCATION. SKUs and barcodes share ONE namespace across products
 *     and variants — scanning a code at the till must never be ambiguous — so a
 *     single place has to allocate both, checking both tables.
 *  2. THE VARIANT CONTRACT. A variable product's prices and stock live on its
 *     variants; a standard product's live on itself. Saving one shape while the
 *     type says the other is how a catalogue quietly rots.
 *  3. THE GATES. Product quota (#79), the variants feature (#125), and the rule
 *     that a service can never track stock.
 *
 * Everything writes inside a transaction: a product whose variants half-saved
 * is worse than no product at all (#98).
 */
class ProductService
{
    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $variants
     *
     * @throws FeatureUnavailableException|LimitExceededException
     */
    public function create(array $data, array $variants = []): Product
    {
        $type = $this->resolveType($data['type'] ?? null);

        $this->assertVariantsAllowed($type);
        $this->limits->assertCanCreate(LimitRegistry::PRODUCTS);

        return DB::transaction(function () use ($data, $variants, $type): Product {
            $product = new Product([
                'category_id' => $this->resolveId(Category::class, $data['category_id'] ?? null, 'category'),
                'brand_id' => $this->resolveId(Brand::class, $data['brand_id'] ?? null, 'brand'),
                'unit_id' => $this->resolveId(Unit::class, $data['unit_id'] ?? null, 'unit'),
                'type' => $type,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'image_path' => $data['image_path'] ?? null,
                'cost_price' => $this->money($data['cost_price'] ?? 0),
                'selling_price' => $this->money($data['selling_price'] ?? 0),
                'tax_rate' => $this->nullableNumber($data['tax_rate'] ?? null),
                // A service can never carry stock, whatever the form said (#25).
                'track_inventory' => $type->tracksStock() && (bool) ($data['track_inventory'] ?? true),
                // Batches only make sense on something that carries stock, and
                // only when the plan includes them (#34).
                'tracks_batches' => $this->resolveBatchTracking($type, $data),
                'alert_quantity' => $this->nullableNumber($data['alert_quantity'] ?? null),
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (($upload = $data['image'] ?? null) instanceof UploadedFile) {
                $product->image_path = $this->storeImage($upload);
            }

            $product->slug = $this->uniqueSlug($data['name']);
            $product->sku = $this->allocateSku($data['sku'] ?? null, $data['name']);
            $product->barcode = $this->allocateBarcode($data['barcode'] ?? null, (bool) ($data['generate_barcode'] ?? false));
            $product->save();

            $this->syncVariants($product, $variants);

            $this->limits->flush();
            $this->audit->log(
                'product.created',
                $product,
                "Product \"{$product->name}\" created.",
                ['sku' => $product->sku, 'type' => $type->value],
            );

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $variants
     */
    public function update(Product $product, array $data, array $variants = []): Product
    {
        $type = $this->resolveType($data['type'] ?? $product->type->value);

        if ($type !== $product->type) {
            $this->assertVariantsAllowed($type);
        }

        return DB::transaction(function () use ($product, $data, $variants, $type): Product {
            $product->fill([
                'category_id' => $this->resolveId(Category::class, $data['category_id'] ?? null, 'category'),
                'brand_id' => $this->resolveId(Brand::class, $data['brand_id'] ?? null, 'brand'),
                'unit_id' => $this->resolveId(Unit::class, $data['unit_id'] ?? null, 'unit'),
                'type' => $type,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                // A MISSING cost key means "leave it alone", not "set it to
                // zero": the form hides cost from users without
                // `products.view_cost` (#52), and saving that form must not
                // quietly wipe what they were never shown.
                'cost_price' => array_key_exists('cost_price', $data)
                    ? $this->money($data['cost_price'])
                    : $product->cost_price,
                'selling_price' => $this->money($data['selling_price'] ?? 0),
                'tax_rate' => $this->nullableNumber($data['tax_rate'] ?? null),
                'track_inventory' => $type->tracksStock() && (bool) ($data['track_inventory'] ?? true),
                'tracks_batches' => $this->resolveBatchTracking($type, $data),
                'alert_quantity' => $this->nullableNumber($data['alert_quantity'] ?? null),
            ]);

            /*
             | An image is REPLACED, not accumulated: the old file is deleted the
             | moment a new one lands, because orphaned uploads are how a disk
             | quietly fills up over a year. Removing the picture deletes it too.
             */
            if (($upload = $data['image'] ?? null) instanceof UploadedFile) {
                $this->deleteImage($product->image_path);
                $product->image_path = $this->storeImage($upload);
            } elseif ($data['remove_image'] ?? false) {
                $this->deleteImage($product->image_path);
                $product->image_path = null;
            } elseif (array_key_exists('image_path', $data)) {
                $product->image_path = $data['image_path'];
            }

            if (array_key_exists('is_active', $data)) {
                $product->is_active = (bool) $data['is_active'];
            }

            if ($product->isDirty('name')) {
                $product->slug = $this->uniqueSlug($data['name'], $product->id);
            }

            // A blank SKU field means "keep the one you have", never "clear it".
            if (filled($data['sku'] ?? null) && $data['sku'] !== $product->sku) {
                $product->sku = $this->allocateSku($data['sku'], $product->name, $product->id);
            }

            if (array_key_exists('barcode', $data) || ($data['generate_barcode'] ?? false)) {
                $barcode = $this->allocateBarcode(
                    $data['barcode'] ?? null,
                    (bool) ($data['generate_barcode'] ?? false),
                    $product->id,
                );

                // Only overwrite when something was actually supplied or asked
                // for — an untouched form must not wipe an existing code.
                if ($barcode !== null || array_key_exists('barcode', $data)) {
                    $product->barcode = $barcode;
                }
            }

            $product->save();

            $this->syncVariants($product, $variants);

            $this->audit->log('product.updated', $product, "Product \"{$product->name}\" updated.", ['sku' => $product->sku]);

            return $product;
        });
    }

    public function setActive(Product $product, bool $active): Product
    {
        $product->is_active = $active;
        $product->save();

        $this->audit->log(
            $active ? 'product.activated' : 'product.deactivated',
            $product,
            "Product \"{$product->name}\" ".($active ? 'activated' : 'deactivated').'.',
        );

        return $product;
    }

    /**
     * Only a product nothing references can actually be removed; anything with
     * history is deactivated instead (#104, #198). Its variants go with it.
     */
    public function delete(Product $product): bool
    {
        if (! $product->canBeDeleted()) {
            return false;
        }

        $name = $product->name;

        $imagePath = $product->image_path;

        DB::transaction(function () use ($product): void {
            $product->variants()->delete();
            $product->delete();
        });

        // Only once the row is really gone — a rolled-back delete must not take
        // the picture with it.
        $this->deleteImage($imagePath);

        $this->limits->flush();
        $this->audit->log('product.deleted', $product, "Product \"{$name}\" deleted.");

        return true;
    }

    // ------------------------------------------------------------- variants

    /**
     * Replace the product's variant set with what was submitted.
     *
     * Rows the form no longer lists are soft-deleted rather than removed: a
     * variant that has been sold must keep resolving on old invoices (#198).
     * Rows that come back with the same id keep that id, so history stays
     * attached to the same variant.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    protected function syncVariants(Product $product, array $variants): void
    {
        if (! $product->hasVariants()) {
            // Switching away from variable: the variants stop being sellable but
            // are kept, in case the shop switches back or has history on them.
            $product->variants()->delete();

            return;
        }

        $keptIds = [];

        foreach (array_values($variants) as $index => $row) {
            $options = $this->cleanOptions($row['options'] ?? []);
            $name = filled($row['name'] ?? null)
                ? (string) $row['name']
                : ProductVariant::nameFromOptions($options);

            $variant = filled($row['id'] ?? null)
                ? $product->variants()->withTrashed()->find((int) $row['id'])
                : null;

            $variant ??= new ProductVariant(['product_id' => $product->id]);

            $fill = [
                'product_id' => $product->id,
                'name' => $name,
                'options' => $options,
                'selling_price' => $this->money($row['selling_price'] ?? 0),
                'alert_quantity' => $this->nullableNumber($row['alert_quantity'] ?? null),
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $index,
            ];

            // Same rule as the product itself: no cost key means keep what is
            // already there (#52). A brand-new variant starts at zero.
            if (array_key_exists('cost_price', $row)) {
                $fill['cost_price'] = $this->money($row['cost_price']);
            } elseif (! $variant->exists) {
                $fill['cost_price'] = 0;
            }

            $variant->fill($fill);

            if ($variant->trashed()) {
                $variant->restore();
            }

            if (! $variant->exists || filled($row['sku'] ?? null)) {
                $variant->sku = $this->allocateSku(
                    $row['sku'] ?? null,
                    $product->name.' '.$name,
                    null,
                    $variant->exists ? $variant->id : null,
                );
            }

            if (array_key_exists('barcode', $row) || ($row['generate_barcode'] ?? false)) {
                $variant->barcode = $this->allocateBarcode(
                    $row['barcode'] ?? null,
                    (bool) ($row['generate_barcode'] ?? false),
                    null,
                    $variant->exists ? $variant->id : null,
                );
            }

            $variant->save();
            $keptIds[] = $variant->id;
        }

        $product->variants()
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();

        $product->unsetRelation('variants');
    }

    /**
     * @param  mixed  $options
     * @return array<string, string>
     */
    protected function cleanOptions($options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $clean = [];

        foreach ($options as $key => $value) {
            $key = trim((string) $key);
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($key !== '' && $value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    // -------------------------------------------------------------- codes

    /**
     * Allocate a SKU that is free across BOTH products and variants.
     *
     * A supplied code is honoured (and rejected if taken); a blank one is
     * generated from the name, which is what most shops actually want.
     */
    public function allocateSku(?string $requested, string $nameForFallback, ?int $ignoreProductId = null, ?int $ignoreVariantId = null): string
    {
        $requested = $requested !== null ? strtoupper(trim($requested)) : null;

        if ($requested !== null && $requested !== '') {
            abort_if(
                $this->skuTaken($requested, $ignoreProductId, $ignoreVariantId),
                422,
                "The SKU \"{$requested}\" is already used by another product or variant.",
            );

            return $requested;
        }

        $base = Str::upper(Str::of($nameForFallback)->slug('')->limit(8, ''));
        $base = $base !== '' ? $base : 'SKU';

        do {
            $candidate = $base.'-'.Str::upper(Str::random(5));
        } while ($this->skuTaken($candidate, $ignoreProductId, $ignoreVariantId));

        return $candidate;
    }

    /**
     * A supplied barcode is honoured; `generate` mints an EAN-13 in the
     * in-store range (#27). Returns null when neither was asked for — plenty of
     * products never carry a barcode.
     */
    public function allocateBarcode(?string $requested, bool $generate = false, ?int $ignoreProductId = null, ?int $ignoreVariantId = null): ?string
    {
        $requested = $requested !== null ? trim($requested) : null;

        if ($requested !== null && $requested !== '') {
            abort_if(
                $this->barcodeTaken($requested, $ignoreProductId, $ignoreVariantId),
                422,
                "The barcode \"{$requested}\" is already used by another product or variant.",
            );

            return $requested;
        }

        if (! $generate) {
            return null;
        }

        do {
            $candidate = $this->generateEan13();
        } while ($this->barcodeTaken($candidate, $ignoreProductId, $ignoreVariantId));

        return $candidate;
    }

    /**
     * An EAN-13 in the 20–29 prefix range, which GS1 reserves for restricted
     * circulation — i.e. codes a shop mints for itself. Using a real
     * manufacturer prefix would risk colliding with an actual product.
     */
    protected function generateEan13(): string
    {
        $digits = '2'.str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);

        $sum = 0;

        foreach (str_split($digits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        $check = (10 - ($sum % 10)) % 10;

        return $digits.$check;
    }

    protected function skuTaken(string $sku, ?int $ignoreProductId = null, ?int $ignoreVariantId = null): bool
    {
        $inProducts = Product::withTrashed()
            ->where('sku', $sku)
            ->when($ignoreProductId !== null, fn ($q) => $q->where('id', '!=', $ignoreProductId))
            ->exists();

        $inVariants = ProductVariant::withTrashed()
            ->where('sku', $sku)
            ->when($ignoreVariantId !== null, fn ($q) => $q->where('id', '!=', $ignoreVariantId))
            ->exists();

        return $inProducts || $inVariants;
    }

    protected function barcodeTaken(string $barcode, ?int $ignoreProductId = null, ?int $ignoreVariantId = null): bool
    {
        $inProducts = Product::withTrashed()
            ->where('barcode', $barcode)
            ->when($ignoreProductId !== null, fn ($q) => $q->where('id', '!=', $ignoreProductId))
            ->exists();

        $inVariants = ProductVariant::withTrashed()
            ->where('barcode', $barcode)
            ->when($ignoreVariantId !== null, fn ($q) => $q->where('id', '!=', $ignoreVariantId))
            ->exists();

        return $inProducts || $inVariants;
    }

    // ------------------------------------------------------------- internals

    /**
     * Store an uploaded image and return its path (#149, #101).
     *
     * `store()` names the file randomly, so the caller never chooses where it
     * lands and cannot overwrite another tenant's picture. The tenant id is in
     * the folder purely to keep the directory browsable by a human.
     */
    protected function storeImage(UploadedFile $file): string
    {
        $businessId = $this->tenant->businessId() ?? 0;

        return $file->store(
            config('uploads.products.path').'/'.$businessId,
            config('uploads.products.disk'),
        );
    }

    protected function deleteImage(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk(config('uploads.products.disk'))->delete($path);
    }

    protected function resolveType(mixed $type): ProductType
    {
        if ($type instanceof ProductType) {
            return $type;
        }

        return ProductType::tryFrom((string) $type) ?? ProductType::Standard;
    }

    /**
     * Batch tracking needs three things to be true at once: the product carries
     * stock, the shop asked for it, and the plan includes it (#34). Silently
     * storing "true" on a plan without the feature would mean the flag springs
     * to life on an upgrade, which nobody asked for.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveBatchTracking(ProductType $type, array $data): bool
    {
        if (! $type->tracksStock() || ! (bool) ($data['tracks_batches'] ?? false)) {
            return false;
        }

        return $this->features->anyOf([
            FeatureRegistry::INVENTORY_EXPIRY_TRACKING,
            FeatureRegistry::CATALOG_BATCH_TRACKING,
        ]);
    }

    protected function assertVariantsAllowed(ProductType $type): void
    {
        if ($type->hasVariants() && ! $this->features->enabled(FeatureRegistry::CATALOG_VARIANTS)) {
            throw new FeatureUnavailableException(
                FeatureRegistry::CATALOG_VARIANTS,
                'Product variants',
            );
        }
    }

    /**
     * Look an id up through its tenant-scoped model, so a category or brand from
     * another business is simply not found.
     *
     * @param  class-string<Model>  $model
     */
    protected function resolveId(string $model, mixed $id, string $label): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $row = $model::find((int) $id);

        abort_if($row === null, 422, "That {$label} does not exist in this business.");

        return $row->id;
    }

    protected function money(mixed $value): float
    {
        return max(0, (float) ($value ?: 0));
    }

    protected function nullableNumber(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : max(0, (float) $value);
    }

    protected function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;

        while (Product::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
