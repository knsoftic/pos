<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Exceptions\LimitExceededException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Support\LimitRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk import and export of the catalogue (#150, #151).
 *
 * THE IMPORT IS ALL-OR-NOTHING. A spreadsheet that half-imports is the worst
 * possible outcome: the shop cannot tell which rows landed, so they either
 * re-import and get duplicates, or delete everything and start again. One
 * transaction, and a row that fails takes the whole file with it — with a report
 * naming the LINE NUMBER, because "invalid price" is useless across 900 rows.
 *
 * MATCHING IS BY SKU. A row whose SKU already exists UPDATES that product rather
 * than creating a second one, which is what a shop means when it re-uploads a
 * corrected price list. A row with no SKU is always new.
 *
 * The quota is checked against the number of rows that would CREATE something,
 * before anything is written (#79) — telling someone their 500-row file failed
 * at row 480 wastes their afternoon.
 */
class ProductImportService
{
    /** The columns an import understands. Anything else in the file is ignored. */
    public const COLUMNS = [
        'name', 'sku', 'barcode', 'type', 'category', 'brand', 'unit',
        'cost_price', 'selling_price', 'tax_rate', 'alert_quantity',
        'track_inventory', 'is_active', 'description',
    ];

    public function __construct(
        protected ProductService $products,
        protected PlanLimitService $limits,
        protected AuditService $audit,
    ) {}

    // ------------------------------------------------------------- the export

    /**
     * The catalogue as CSV rows, ready to stream (#151).
     *
     * Cost price is included only when the caller may see it (#52) — an export
     * is the easiest possible way to walk out with margins.
     *
     * @return \Generator<int, list<string>>
     */
    public function exportRows(bool $includeCost): \Generator
    {
        $header = array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => $includeCost || $column !== 'cost_price',
        ));

        yield $header;

        // Chunked: a catalogue can be tens of thousands of rows, and loading it
        // all to build one string would trade a slow export for a dead process.
        foreach (Product::query()->with(['category', 'brand', 'unit'])->orderBy('name')->cursor() as $product) {
            $row = [
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode ?? '',
                'type' => $product->type->value,
                'category' => $product->category?->name ?? '',
                'brand' => $product->brand?->name ?? '',
                'unit' => $product->unit?->short_name ?? '',
                'cost_price' => (string) $product->cost_price,
                'selling_price' => (string) $product->selling_price,
                'tax_rate' => $product->tax_rate === null ? '' : (string) $product->tax_rate,
                'alert_quantity' => $product->alert_quantity === null ? '' : (string) $product->alert_quantity,
                'track_inventory' => $product->track_inventory ? 'yes' : 'no',
                'is_active' => $product->is_active ? 'yes' : 'no',
                'description' => (string) $product->description,
            ];

            if (! $includeCost) {
                unset($row['cost_price']);
            }

            yield array_values($row);
        }
    }

    /** A blank file with the right headers — the fastest way to explain the format. */
    public function templateRows(): array
    {
        return [
            self::COLUMNS,
            [
                'Cola 500ml', 'COLA-500', '2001234567893', 'standard', 'Drinks', 'Acme', 'pc',
                '45.50', '70.00', '', '12', 'yes', 'yes', 'Chilled 500ml bottle',
            ],
        ];
    }

    // ------------------------------------------------------------- the import

    /**
     * Read, validate, and apply a CSV.
     *
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(UploadedFile $file, bool $canSetCost): array
    {
        [$rows, $errors] = $this->readRows($file);

        if ($errors !== []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => count($rows), 'errors' => $errors];
        }

        // Work out what is new BEFORE writing, so the quota answer is honest.
        $existingSkus = Product::query()
            ->whereIn('sku', array_filter(array_column($rows, 'sku')))
            ->pluck('id', 'sku');

        $creates = 0;

        foreach ($rows as $row) {
            if (blank($row['sku']) || ! $existingSkus->has($row['sku'])) {
                $creates++;
            }
        }

        if ($creates > 0) {
            // Throws LimitExceededException, which the controller renders — the
            // shop hears "your plan allows 500" rather than "row 481 failed".
            $this->limits->assertCanCreate(LimitRegistry::PRODUCTS, $creates);
        }

        $created = 0;
        $updated = 0;
        $failures = [];

        /*
         | The transaction is driven by hand rather than through DB::transaction()
         | for one reason: a bad row must ROLL BACK AND REPORT, not roll back and
         | explode. Throwing to trigger the rollback would leave the shop looking
         | at a 500 page instead of a list of the lines that need fixing — which
         | is the whole point of collecting line numbers in the first place.
         */
        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                $line = $row['__line'];

                try {
                    $attributes = $this->toAttributes($row, $canSetCost);

                    $existingId = blank($row['sku']) ? null : $existingSkus->get($row['sku']);

                    if ($existingId !== null) {
                        $this->products->update(Product::findOrFail($existingId), $attributes);
                        $updated++;

                        continue;
                    }

                    $this->products->create($attributes);
                    $created++;
                } catch (LimitExceededException $e) {
                    // The quota is a plan answer, not a bad row: it deserves its
                    // own message, so it rolls back and travels on.
                    DB::rollBack();

                    throw $e;
                } catch (\Throwable $e) {
                    $failures[] = "Line {$line}: ".$e->getMessage();
                }
            }

            // One bad row rolls the whole file back. See the class docblock.
            if ($failures !== []) {
                DB::rollBack();

                return ['created' => 0, 'updated' => 0, 'skipped' => count($rows), 'errors' => $failures];
            }

            DB::commit();
        } catch (LimitExceededException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->audit->log(
            'product.imported',
            null,
            "Catalogue import: {$created} created, {$updated} updated.",
            ['created' => $created, 'updated' => $updated],
        );

        $this->limits->flush();

        return ['created' => $created, 'updated' => $updated, 'skipped' => 0, 'errors' => []];
    }

    // ------------------------------------------------------------- internals

    /**
     * Parse the file into rows keyed by column name, collecting structural
     * errors as we go.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    protected function readRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [[], ['That file could not be read.']];
        }

        $header = fgetcsv($handle);

        if ($header === false || $header === null) {
            fclose($handle);

            return [[], ['That file is empty.']];
        }

        // Excel writes a BOM on the first cell and it silently breaks the header
        // match — every import tool learns this the hard way once.
        $header = array_map(
            fn ($column) => Str::of((string) $column)->replace("\u{FEFF}", '')->trim()->lower()->replace(' ', '_')->toString(),
            $header,
        );

        if (! in_array('name', $header, true)) {
            fclose($handle);

            return [[], ['The file needs a "name" column. Download the template to see the format.']];
        }

        $rows = [];
        $errors = [];
        $line = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;

            // A trailing blank line is not an error, it is how files end.
            if ($data === [null] || $data === ['']) {
                continue;
            }

            $row = [];

            foreach ($header as $i => $column) {
                $row[$column] = isset($data[$i]) ? trim((string) $data[$i]) : null;
            }

            if (blank($row['name'] ?? null)) {
                $errors[] = "Line {$line}: every row needs a name.";

                continue;
            }

            $row['__line'] = $line;
            $rows[] = $row;

            if (count($rows) > 5000) {
                $errors[] = 'That file has more than 5,000 rows — split it up.';
                break;
            }
        }

        fclose($handle);

        if ($rows === [] && $errors === []) {
            $errors[] = 'That file has a header but no rows.';
        }

        return [$rows, $errors];
    }

    /**
     * Turn one CSV row into what {@see ProductService} expects.
     *
     * Categories, brands and units are matched BY NAME and created when absent:
     * a shop uploading a supplier's list should not have to pre-create thirty
     * categories by hand first.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function toAttributes(array $row, bool $canSetCost): array
    {
        $type = ProductType::tryFrom((string) ($row['type'] ?? '')) ?? ProductType::Standard;

        abort_if(
            $type === ProductType::Variable,
            422,
            'Variable products cannot be imported — their variants need setting up by hand.',
        );

        $attributes = [
            'name' => $row['name'],
            'type' => $type->value,
            'sku' => blank($row['sku'] ?? null) ? null : $row['sku'],
            'description' => $row['description'] ?? null,
            'selling_price' => $this->number($row['selling_price'] ?? 0) ?? 0,
            'tax_rate' => $this->number($row['tax_rate'] ?? null),
            'alert_quantity' => $this->number($row['alert_quantity'] ?? null),
            'track_inventory' => $this->boolean($row['track_inventory'] ?? 'yes', true),
            'is_active' => $this->boolean($row['is_active'] ?? 'yes', true),
            'category_id' => $this->findOrCreateCategory($row['category'] ?? null),
            'brand_id' => $this->findOrCreateBrand($row['brand'] ?? null),
            'unit_id' => $this->findUnit($row['unit'] ?? null),
        ];

        if (filled($row['barcode'] ?? null)) {
            $attributes['barcode'] = $row['barcode'];
        }

        // #52 again: someone who cannot see cost cannot set it through a file
        // either. The column is simply ignored.
        if ($canSetCost) {
            $attributes['cost_price'] = $this->number($row['cost_price'] ?? 0) ?? 0;
        }

        return $attributes;
    }

    protected function findOrCreateCategory(?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        $existing = Category::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $category = new Category([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_active' => true,
        ]);
        $category->save();

        return $category->id;
    }

    protected function findOrCreateBrand(?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        $existing = Brand::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $brand = new Brand([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_active' => true,
        ]);
        $brand->save();

        return $brand->id;
    }

    /** Units are NOT auto-created: an invented unit of measure is a data error. */
    protected function findUnit(?string $shortName): ?int
    {
        if (blank($shortName)) {
            return null;
        }

        $unit = Unit::query()
            ->where('short_name', $shortName)
            ->orWhere('name', $shortName)
            ->first();

        abort_if($unit === null, 422, "there is no unit called \"{$shortName}\" — create it first.");

        return $unit->id;
    }

    protected function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Spreadsheets export "1,234.50" without being asked.
        $clean = str_replace([',', ' '], '', (string) $value);

        abort_unless(is_numeric($clean), 422, "\"{$value}\" is not a number.");

        return (float) $clean;
    }

    protected function boolean(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(Str::lower((string) $value), ['1', 'yes', 'y', 'true', 'active'], true);
    }
}
