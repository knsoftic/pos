<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one place stock is allowed to change (#185).
 *
 * EVERY module that moves stock — purchases (#29), the POS, returns, transfers,
 * stock takes — comes through {@see createMovement()}. Not as a convention: it
 * is the only method that writes the ledger, and `stocks.quantity` is not
 * fillable, so there is no second way to do it. That is what makes the ledger
 * complete, and a complete ledger is the difference between a stock figure and
 * a guess.
 *
 * WHAT ONE MOVEMENT DOES, atomically:
 *   1. Locks the shelf row (SELECT … FOR UPDATE) so two tills cannot both sell
 *      the last unit.
 *   2. Applies the type's direction to get a signed quantity.
 *   3. Refuses to go below zero unless the business allows it (#142).
 *   4. Re-weights the average cost when stock comes IN.
 *   5. Writes the ledger line with the balance stamped on it, and updates the
 *      cached balance — both inside the same transaction, so they cannot drift.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: decide whether the caller was allowed to do
 * this. Permissions and plan features are checked by the route and the calling
 * service — an inventory service that also did authorisation would be asked to
 * bypass itself the first time a background job needed to post a correction.
 */
class InventoryService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branchContext,
        protected FeatureService $features,
        protected AuditService $audit,
    ) {}

    // ------------------------------------------------------------- questions

    /**
     * Is stock tracking part of this plan at all?
     *
     * Callers that move stock as a SIDE EFFECT (a sale, a purchase) must ask
     * this first and skip quietly when it is false — a tenant on a plan without
     * inventory should still be able to sell things. Callers where moving stock
     * IS the action (an adjustment screen) are gated by their route instead, and
     * {@see createMovement()} refuses outright.
     */
    public function isTrackingEnabled(): bool
    {
        return $this->features->enabled(FeatureRegistry::INVENTORY_STOCK_TRACKING);
    }

    /**
     * How many are on the shelf right now (#185).
     *
     * Reads the cached balance, which is exactly what the ledger says — see the
     * class docblock. Returns 0.0 for a shelf that has never been touched, and
     * for anything that does not carry stock at all.
     */
    public function getAvailableStock(Product|int $product, ?int $variantId = null, ?int $branchId = null): float
    {
        $product = $this->resolveProduct($product);

        if (! $product->tracksStock()) {
            return 0.0;
        }

        $branchId = $this->resolveBranchId($branchId);

        /*
         | Two cases answer with a SUM rather than a single shelf:
         |   - no branch in play (a report across the whole business);
         |   - a variable product asked without naming a variant, which is the
         |     natural "how many T-shirts do we have?" question. Stock lives on
         |     the variants, so the honest answer is their total.
         */
        if ($branchId === null || ($product->hasVariants() && $variantId === null)) {
            return round((float) $this->shelves($product->id, $variantId)
                ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
                ->sum('quantity'), 4);
        }

        $stock = $this->findShelf($branchId, $product->id, $variantId);

        return $stock === null ? 0.0 : (float) $stock->quantity;
    }

    /** The same question, asked of every branch at once. */
    public function stockByBranch(Product|int $product, ?int $variantId = null): array
    {
        $product = $this->resolveProduct($product);

        return $this->shelves($product->id, $variantId)
            ->with('branch:id,name')
            ->get()
            ->mapWithKeys(fn (Stock $stock) => [
                $stock->branch_id => [
                    'branch' => $stock->branch?->name,
                    'quantity' => (float) $stock->quantity,
                    'value' => $stock->value(),
                ],
            ])
            ->all();
    }

    public function hasEnough(Product|int $product, float $quantity, ?int $variantId = null, ?int $branchId = null): bool
    {
        if ($this->allowsNegativeStock()) {
            return true;
        }

        return $this->getAvailableStock($product, $variantId, $branchId) >= $quantity;
    }

    /** Whether this business lets a shelf go below zero (#142). */
    public function allowsNegativeStock(): bool
    {
        // Phase 11 moves this to a per-business setting; the config is the
        // fallback either way, so nothing outside this method changes then.
        return (bool) config('inventory.allow_negative_stock', false);
    }

    // --------------------------------------------------------------- the write

    /**
     * Post one movement. The only way stock ever changes.
     *
     * @param  array{
     *     product: Product|int,
     *     variant_id?: int|null,
     *     branch_id?: int|null,
     *     type: StockMovementType|string,
     *     quantity: float,
     *     unit_cost?: float|null,
     *     reference?: Model|null,
     *     reason?: string|null,
     *     notes?: string|null,
     *     user_id?: int|null,
     * }  $data
     *
     * `quantity` is given as a POSITIVE amount for directional types — the type
     * decides the sign. For `adjustment` and `stock_take`, which go either way,
     * the caller's sign is honoured.
     *
     * @throws InsufficientStockException
     */
    public function createMovement(array $data): StockMovement
    {
        $product = $this->resolveProduct($data['product']);
        $type = $this->resolveType($data['type']);
        $variantId = $this->resolveVariantId($product, $data['variant_id'] ?? null);
        $branchId = $this->resolveBranchId($data['branch_id'] ?? null);

        abort_if($branchId === null, 422, 'A stock movement needs a branch.');
        abort_unless($this->branchContext->allows($branchId), 403, 'That branch is outside your access.');

        abort_unless(
            $product->tracksStock(),
            422,
            "\"{$product->name}\" does not carry stock, so it cannot have a stock movement.",
        );

        abort_unless(
            $this->isTrackingEnabled(),
            422,
            'Stock tracking is not part of your current plan.',
        );

        $signed = $this->signedQuantity($type, (float) $data['quantity']);

        abort_if($signed === 0.0, 422, 'A stock movement of zero changes nothing.');

        return DB::transaction(function () use ($product, $variantId, $branchId, $type, $signed, $data): StockMovement {
            // Lock the shelf for the rest of this transaction. Two tills selling
            // the last unit at the same moment queue here instead of both
            // succeeding.
            $stock = $this->lockShelf($branchId, $product->id, $variantId);

            $before = (float) $stock->quantity;
            $after = round($before + $signed, 4);

            if ($after < 0 && ! $this->allowsNegativeStock()) {
                throw new InsufficientStockException(
                    $this->shelfLabel($product, $variantId),
                    $before,
                    abs($signed),
                );
            }

            $unitCost = $this->resolveUnitCost($type, $data['unit_cost'] ?? null, $stock, $product, $variantId);

            // Incoming stock re-weights what the shelf is worth. Outgoing stock
            // leaves the average alone — it consumes value at the cost already
            // on the books.
            if ($signed > 0 && $type->carriesCost()) {
                $stock->average_cost = $this->weightedAverage($before, (float) $stock->average_cost, $signed, $unitCost);
            }

            $stock->quantity = $after;
            $stock->last_movement_at = now();
            $stock->save();

            $movement = new StockMovement([
                'business_id' => $stock->business_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'type' => $type,
                'quantity' => $signed,
                'unit_cost' => $signed > 0 ? $unitCost : (float) $stock->average_cost,
                'balance_after' => $after,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? auth('web')->id(),
                'created_at' => now(),
            ]);

            if (($reference = $data['reference'] ?? null) instanceof Model) {
                $movement->reference_type = $reference->getMorphClass();
                $movement->reference_id = $reference->getKey();
            }

            $movement->save();

            return $movement;
        });
    }

    /**
     * A manual correction, with a reason (#31).
     *
     * `$quantity` is signed: +5 found five more, −2 lost two. The reason is
     * required by the caller's validation, not optional politeness — an
     * unexplained stock change is how shrinkage hides.
     */
    public function adjust(Product|int $product, float $quantity, string $reason, ?int $variantId = null, ?int $branchId = null, ?string $notes = null): StockMovement
    {
        $movement = $this->createMovement([
            'product' => $product,
            'variant_id' => $variantId,
            'branch_id' => $branchId,
            'type' => StockMovementType::Adjustment,
            'quantity' => $quantity,
            'reason' => $reason,
            'notes' => $notes,
        ]);

        $this->audit->log(
            'stock.adjusted',
            $movement,
            sprintf(
                'Stock adjusted by %s for "%s".',
                $movement->signedQuantity(),
                $this->shelfLabel($this->resolveProduct($product), $variantId),
            ),
            [
                'reason' => $reason,
                'balance_after' => (float) $movement->balance_after,
                'branch_id' => $movement->branch_id,
            ],
        );

        return $movement;
    }

    /**
     * Opening stock (#152) — what was already on the shelf when the shop started
     * using the system.
     *
     * Posted as a movement like everything else, so day one is part of the same
     * history as day one thousand. Idempotent per shelf: a second opening entry
     * would be a correction, and corrections are adjustments.
     */
    public function recordOpeningStock(Product|int $product, float $quantity, float $unitCost, ?int $variantId = null, ?int $branchId = null): ?StockMovement
    {
        $product = $this->resolveProduct($product);
        $branchId = $this->resolveBranchId($branchId);

        $alreadyOpened = StockMovement::query()
            ->where('branch_id', $branchId)
            ->forShelf($product->id, $variantId)
            ->where('type', StockMovementType::Opening)
            ->exists();

        if ($alreadyOpened || $quantity <= 0) {
            return null;
        }

        return $this->createMovement([
            'product' => $product,
            'variant_id' => $variantId,
            'branch_id' => $branchId,
            'type' => StockMovementType::Opening,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reason' => 'Opening stock',
        ]);
    }

    /**
     * Set a shelf to a counted figure (#31 stock take).
     *
     * Posts the DIFFERENCE, not the total: the ledger records what changed, so
     * the count itself is auditable rather than a silent overwrite.
     */
    public function setStockTo(Product|int $product, float $countedQuantity, string $reason, ?int $variantId = null, ?int $branchId = null): ?StockMovement
    {
        $current = $this->getAvailableStock($product, $variantId, $branchId);
        $difference = round($countedQuantity - $current, 4);

        if ($difference === 0.0) {
            return null;
        }

        return $this->createMovement([
            'product' => $product,
            'variant_id' => $variantId,
            'branch_id' => $branchId,
            'type' => StockMovementType::StockTake,
            'quantity' => $difference,
            'reason' => $reason,
        ]);
    }

    // ------------------------------------------------------------- reporting

    /**
     * The ledger for one shelf, or for everything (#30).
     *
     * Returns a builder rather than results: the screen paginates it, a report
     * aggregates it, and neither should have to undo a decision made here.
     */
    public function ledger(?int $productId = null, ?int $variantId = null, ?int $branchId = null): Builder
    {
        return StockMovement::query()
            ->with(['product:id,name,sku', 'variant:id,name', 'branch:id,name', 'user:id,name'])
            ->when($productId !== null, fn (Builder $q) => $q->where('product_id', $productId))
            ->when($variantId !== null, fn (Builder $q) => $q->where('product_variant_id', $variantId))
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->newestFirst();
    }

    /** Shelves at or below their alert threshold (#33). */
    public function lowStock(?int $branchId = null): Builder
    {
        return Stock::query()
            ->lowStock()
            ->when($branchId !== null, fn (Builder $q) => $q->where('stocks.branch_id', $branchId))
            ->with(['product:id,name,sku,alert_quantity,track_inventory', 'variant:id,name,alert_quantity', 'branch:id,name']);
    }

    /**
     * What the stock on hand is worth (#28).
     *
     * Negative balances are included rather than clamped: an oversold shelf is a
     * real problem, and hiding it from the valuation would make the number
     * agree with the shop's hopes instead of its records.
     *
     * @return array{quantity: float, value: float, shelves: int, out_of_stock: int, low: int}
     */
    public function valuation(?int $branchId = null): array
    {
        $rows = Stock::query()
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->with(['product:id,alert_quantity,track_inventory', 'variant:id,alert_quantity'])
            ->get();

        return [
            'quantity' => round((float) $rows->sum(fn (Stock $s) => (float) $s->quantity), 4),
            'value' => round($rows->sum(fn (Stock $s) => $s->value()), 2),
            'shelves' => $rows->count(),
            'out_of_stock' => $rows->filter(fn (Stock $s) => $s->isOutOfStock())->count(),
            'low' => $rows->filter(fn (Stock $s) => $s->isLow() && ! $s->isOutOfStock())->count(),
        ];
    }

    /**
     * Rebuild a shelf's cached balance from the ledger.
     *
     * Both the repair tool and the proof: if this ever changes a number, the
     * cache had drifted from the truth, and the truth is the ledger.
     *
     * @return array{before: float, after: float, drifted: bool}
     */
    public function recalculate(int $branchId, int $productId, ?int $variantId = null): array
    {
        return DB::transaction(function () use ($branchId, $productId, $variantId): array {
            $stock = $this->lockShelf($branchId, $productId, $variantId);

            $before = (float) $stock->quantity;
            $after = round((float) StockMovement::query()
                ->where('branch_id', $branchId)
                ->forShelf($productId, $variantId)
                ->sum('quantity'), 4);

            $stock->quantity = $after;
            $stock->save();

            return [
                'before' => $before,
                'after' => $after,
                'drifted' => abs($before - $after) > 0.00005,
            ];
        });
    }

    // ------------------------------------------------------------- internals

    /**
     * Find or create the shelf row, then lock it.
     *
     * firstOrCreate first, because two concurrent first-ever movements would
     * otherwise both try to insert; the unique index makes the loser fail, and
     * retrying the read is the correct response.
     */
    protected function lockShelf(int $branchId, int $productId, ?int $variantId): Stock
    {
        $attributes = [
            'branch_id' => $branchId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
        ];

        try {
            Stock::query()->allBranches()->firstOrCreate($attributes);
        } catch (UniqueConstraintViolationException) {
            // Someone else created it between our read and our insert. Fine —
            // the row we wanted now exists.
        }

        return Stock::query()
            ->allBranches()
            ->where($attributes)
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function findShelf(int $branchId, int $productId, ?int $variantId): ?Stock
    {
        return Stock::query()
            ->allBranches()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->when($variantId === null,
                fn (Builder $q) => $q->whereNull('product_variant_id'),
                fn (Builder $q) => $q->where('product_variant_id', $variantId),
            )
            ->first();
    }

    /** Every shelf for a product, across the branches the user may reach. */
    protected function shelves(int $productId, ?int $variantId = null): Builder
    {
        return Stock::query()
            ->where('product_id', $productId)
            ->when($variantId !== null, fn (Builder $q) => $q->where('product_variant_id', $variantId));
    }

    /**
     * The weighted average cost after stock comes in.
     *
     * A negative or zero starting balance has no meaningful average to blend
     * with — the incoming cost simply becomes the new one, which is both the
     * arithmetically safe answer and the intuitive one.
     */
    protected function weightedAverage(float $currentQty, float $currentAvg, float $incomingQty, float $incomingCost): float
    {
        if ($currentQty <= 0) {
            return round($incomingCost, 4);
        }

        $totalQty = $currentQty + $incomingQty;

        if ($totalQty <= 0) {
            return round($incomingCost, 4);
        }

        return round((($currentQty * $currentAvg) + ($incomingQty * $incomingCost)) / $totalQty, 4);
    }

    /**
     * What one unit cost. An explicit figure wins; otherwise fall back to the
     * shelf's current average, and then to the catalogue's cost price — a
     * movement with no cost at all would quietly value stock at zero.
     */
    protected function resolveUnitCost(StockMovementType $type, ?float $given, Stock $stock, Product $product, ?int $variantId): float
    {
        if ($given !== null) {
            return round(max(0, $given), 4);
        }

        if ((float) $stock->average_cost > 0) {
            return (float) $stock->average_cost;
        }

        $catalogueCost = $variantId !== null
            ? (float) (ProductVariant::query()->whereKey($variantId)->value('cost_price') ?? 0)
            : (float) $product->cost_price;

        return round(max(0, $catalogueCost), 4);
    }

    /** Apply the type's direction, honouring the caller's sign for signed types. */
    protected function signedQuantity(StockMovementType $type, float $quantity): float
    {
        if ($type->isSigned()) {
            return round($quantity, 4);
        }

        return round(abs($quantity) * $type->direction(), 4);
    }

    protected function resolveProduct(Product|int $product): Product
    {
        if ($product instanceof Product) {
            return $product;
        }

        $found = Product::find($product);

        abort_if($found === null, 404, 'Product not found.');

        return $found;
    }

    /**
     * A variable product's stock lives on its variants, so a movement against
     * the parent is meaningless; a simple product has no variant to name.
     */
    protected function resolveVariantId(Product $product, ?int $variantId): ?int
    {
        if ($product->hasVariants()) {
            abort_if($variantId === null, 422, "\"{$product->name}\" has variants — say which one.");

            $belongs = ProductVariant::query()
                ->whereKey($variantId)
                ->where('product_id', $product->id)
                ->exists();

            abort_unless($belongs, 422, 'That variant does not belong to this product.');

            return $variantId;
        }

        abort_if($variantId !== null, 422, "\"{$product->name}\" has no variants.");

        return null;
    }

    /** Default to the user's own branch — the shelf they are standing at. */
    protected function resolveBranchId(?int $branchId): ?int
    {
        if ($branchId !== null) {
            return $branchId;
        }

        $userBranch = auth('web')->user()?->branch_id;

        if ($userBranch !== null) {
            return (int) $userBranch;
        }

        // An owner with no branch of their own falls back to the main branch,
        // so "adjust this product" from an owner account still has an answer.
        return Branch::query()->where('is_main', true)->value('id')
            ?? Branch::query()->orderBy('id')->value('id');
    }

    protected function resolveType(StockMovementType|string $type): StockMovementType
    {
        if ($type instanceof StockMovementType) {
            return $type;
        }

        $resolved = StockMovementType::tryFrom($type);

        abort_if($resolved === null, 422, "Unknown stock movement type [{$type}].");

        return $resolved;
    }

    protected function shelfLabel(Product $product, ?int $variantId): string
    {
        if ($variantId === null) {
            return $product->name;
        }

        $variantName = ProductVariant::query()->whereKey($variantId)->value('name');

        return $variantName === null ? $product->name : $product->name.' — '.$variantName;
    }
}
