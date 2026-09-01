<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Buying goods (#35, #36, #119, #183).
 *
 * THE CENTRAL DECISION: ordering creates nothing. A purchase order is a request;
 * until goods arrive the shop owns no more stock and owes no more money. Both
 * the shelf and the supplier's account move on RECEIPT, and only for what
 * actually turned up. That is why a short delivery is an ordinary state
 * (`Partial`) rather than an error to be corrected.
 *
 * THE RECEIPT FLOW, which #119 spells out and which happens in ONE transaction:
 *
 *      validate  → the purchase can receive, the branch is reachable, the
 *                  quantities are sane and not more than were ordered
 *      lines     → each line's `quantity_received` grows by the NEW amount
 *      stock     → one movement per line, through InventoryService, carrying
 *                  the line's own cost so the shelf is valued correctly
 *      ledger    → the supplier is debited the value of what arrived
 *      payment   → if money was handed over, it is credited straight back
 *      commit    → all of it, or none of it
 *
 * Nothing here writes a stock figure or a balance directly: it calls the two
 * services that own those, so a purchase ends up in the same ledgers as
 * everything else and can be traced from either end.
 */
class PurchaseService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
        protected InventoryService $inventory,
        protected SupplierLedgerService $ledger,
        protected AuditService $audit,
    ) {}

    // -------------------------------------------------------------- drafting

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $data, array $items): Purchase
    {
        $this->assertFeature();

        $supplier = $this->resolveSupplier($data['supplier_id'] ?? null);
        $branchId = $this->resolveBranch($data['branch_id'] ?? null);

        abort_if($items === [], 422, 'A purchase needs at least one line.');

        return DB::transaction(function () use ($data, $items, $supplier, $branchId): Purchase {
            $purchase = new Purchase([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $purchase->reference = $this->nextReference();
            $purchase->status = PurchaseStatus::Draft;
            $purchase->save();

            $this->syncItems($purchase, $items);

            $this->audit->log(
                'purchase.created',
                $purchase,
                "Purchase {$purchase->reference} drafted for {$supplier->name}.",
                ['lines' => count($items), 'total' => (float) $purchase->total],
            );

            return $purchase;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function update(Purchase $purchase, array $data, array $items): Purchase
    {
        $this->assertFeature();

        abort_unless(
            $purchase->status->isEditable(),
            422,
            'Only a draft can be edited — this one has already gone to the supplier.',
        );

        abort_if($items === [], 422, 'A purchase needs at least one line.');

        $supplier = $this->resolveSupplier($data['supplier_id'] ?? $purchase->supplier_id);
        $branchId = $this->resolveBranch($data['branch_id'] ?? $purchase->branch_id);

        return DB::transaction(function () use ($purchase, $data, $items, $supplier, $branchId): Purchase {
            $purchase->fill([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'order_date' => $data['order_date'] ?? $purchase->order_date,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $purchase->save();

            $this->syncItems($purchase, $items);

            $this->audit->log('purchase.updated', $purchase, "Purchase {$purchase->reference} updated.");

            return $purchase;
        });
    }

    /** Send it to the supplier. Still posts nothing — see the class docblock. */
    public function order(Purchase $purchase): Purchase
    {
        $this->assertFeature();

        abort_unless($purchase->status->canBeOrdered(), 422, 'Only a draft can be ordered.');

        $purchase->loadMissing('items', 'supplier');

        abort_if($purchase->items->isEmpty(), 422, 'There is nothing on this purchase to order.');

        abort_if(
            $purchase->supplier?->isBlocked(),
            422,
            "\"{$purchase->supplier?->name}\" is blocked, so nothing can be ordered from them.",
        );

        $purchase->status = PurchaseStatus::Ordered;
        $purchase->ordered_at = now();
        $purchase->save();

        $this->audit->log(
            'purchase.ordered',
            $purchase,
            "Purchase {$purchase->reference} sent to {$purchase->supplier?->name}.",
            ['total' => (float) $purchase->total],
        );

        return $purchase;
    }

    // ------------------------------------------------------------ the receipt

    /**
     * Goods arrive and someone counts them (#35, #119).
     *
     * @param  array<int, float>  $received  purchase item id => quantity arriving NOW.
     *                                       A line left out is taken as "all of
     *                                       what is still outstanding", because
     *                                       a complete delivery is the common
     *                                       case and retyping invites typos.
     * @param  array<string, mixed>  $options  payment: pay_now, payment_method,
     *                                         reference_no; plus received_date.
     */
    public function receive(Purchase $purchase, array $received = [], array $options = []): Purchase
    {
        $this->assertFeature();

        abort_unless(
            $purchase->status->canReceive(),
            422,
            'Only an order that is out with the supplier can be received.',
        );

        abort_unless(
            $this->branches->allows($purchase->branch_id),
            403,
            'That branch is outside your access.',
        );

        $purchase->loadMissing('items.product', 'supplier', 'branch');

        return DB::transaction(function () use ($purchase, $received, $options): Purchase {
            $postedValue = 0.0;
            $anythingArrived = false;

            foreach ($purchase->items as $item) {
                $quantity = array_key_exists($item->id, $received)
                    ? max(0.0, round((float) $received[$item->id], 4))
                    : $item->outstanding();

                if ($quantity <= 0) {
                    continue;
                }

                // Over-delivery is refused rather than silently absorbed: if the
                // supplier sent more than was ordered, that is a conversation to
                // have, not a number to quietly accept onto the bill.
                abort_if(
                    $quantity > $item->outstanding() + 0.00005,
                    422,
                    sprintf(
                        '"%s": %s were ordered and %s already received, so only %s can be taken in.',
                        $item->description,
                        $this->trim((float) $item->quantity_ordered),
                        $this->trim((float) $item->quantity_received),
                        $this->trim($item->outstanding()),
                    ),
                );

                $anythingArrived = true;

                // ---- stock ------------------------------------------------
                // Only when the product still carries stock: a line for a
                // service, or for something later switched off inventory, is
                // billed but never shelved.
                if ($this->inventory->isTrackingEnabled() && $item->product?->tracksStock()) {
                    $this->inventory->createMovement([
                        'product' => $item->product_id,
                        'variant_id' => $item->product_variant_id,
                        'branch_id' => $purchase->branch_id,
                        'type' => StockMovementType::Purchase,
                        'quantity' => $quantity,
                        // The line's own all-in cost, so the shelf is valued at
                        // what this delivery actually cost.
                        'unit_cost' => $item->effectiveUnitCost(),
                        'reference' => $purchase,
                        'reason' => "Purchase {$purchase->reference} from ".$purchase->supplier?->name,
                        'batch_number' => $item->batch_number,
                        'expiry_date' => $item->expiry_date?->toDateString(),
                    ]);
                }

                $item->quantity_received = round((float) $item->quantity_received + $quantity, 4);
                $item->save();

                $postedValue = round($postedValue + ($quantity * $item->effectiveUnitCost()), 2);
            }

            abort_unless($anythingArrived, 422, 'Nothing was marked as arriving.');

            // ---- the supplier's account ----------------------------------
            // Debited the value of what arrived, not the value of what was
            // ordered. You owe for goods you have.
            if ($postedValue > 0) {
                $this->ledger->recordPurchase($purchase->supplier, $postedValue, [
                    'entry_date' => $options['received_date'] ?? null,
                    'description' => "Purchase {$purchase->reference}",
                    'reference' => $purchase,
                    'reference_no' => $purchase->supplier_invoice_no ?: $purchase->reference,
                    'branch_id' => $purchase->branch_id,
                ]);
            }

            $purchase->load('items');
            $purchase->first_received_at ??= now();
            $purchase->status = $purchase->isFullyReceived()
                ? PurchaseStatus::Received
                : PurchaseStatus::Partial;

            if ($purchase->status === PurchaseStatus::Received) {
                $purchase->completed_at = now();
            }

            $purchase->save();

            // ---- payment --------------------------------------------------
            // Paying at the door is one movement of money, in the same
            // transaction as the goods it settles.
            $payNow = round((float) ($options['pay_now'] ?? 0), 2);

            if ($payNow > 0) {
                $this->settle($purchase, $payNow, [
                    'payment_method' => $options['payment_method'] ?? null,
                    'reference_no' => $options['payment_reference_no'] ?? null,
                    'entry_date' => $options['received_date'] ?? null,
                ]);
            }

            $this->audit->log(
                'purchase.received',
                $purchase,
                sprintf(
                    'Purchase %s %s at %s.',
                    $purchase->reference,
                    $purchase->status === PurchaseStatus::Received ? 'fully received' : 'partly received',
                    $purchase->branch?->name,
                ),
                [
                    'value_received' => $postedValue,
                    'outstanding_units' => $purchase->outstandingQuantity(),
                    'paid_now' => $payNow,
                ],
            );

            return $purchase;
        });
    }

    /**
     * Pay a supplier against this bill (#42).
     *
     * The money goes onto the supplier's account, which is the single place a
     * balance lives; `paid_amount` here is only a cache so the purchase can show
     * what is still outstanding without opening the account.
     */
    public function settle(Purchase $purchase, float $amount, array $options = []): Purchase
    {
        abort_if($amount <= 0, 422, 'A payment must be more than zero.');

        abort_unless(
            $purchase->status->hasPosted(),
            422,
            'Nothing is owed on this purchase yet — goods have not arrived.',
        );

        $purchase->loadMissing('supplier', 'items');

        return DB::transaction(function () use ($purchase, $amount, $options): Purchase {
            $this->ledger->recordPayment($purchase->supplier, $amount, [
                'entry_date' => $options['entry_date'] ?? null,
                'description' => "Payment for purchase {$purchase->reference}",
                'reference_no' => $options['reference_no'] ?? $purchase->reference,
                'payment_method' => $options['payment_method'] ?? null,
                'branch_id' => $purchase->branch_id,
            ]);

            $purchase->paid_amount = round((float) $purchase->paid_amount + $amount, 2);
            $purchase->save();

            return $purchase;
        });
    }

    // -------------------------------------------------------------- calling off

    /**
     * Call it off.
     *
     * A draft or an untouched order has posted nothing, so cancelling costs
     * nothing. A PARTLY received order keeps everything that arrived — the stock
     * is on the shelf and the supplier is owed for it; what is abandoned is only
     * the outstanding remainder. Reversing a delivery is a return (#37), not a
     * cancellation.
     */
    public function cancel(Purchase $purchase, string $reason): Purchase
    {
        $this->assertFeature();

        abort_unless(
            $purchase->status->canBeCancelled(),
            422,
            'This purchase is already closed.',
        );

        $purchase->status = PurchaseStatus::Cancelled;
        $purchase->cancelled_at = now();
        $purchase->cancellation_reason = $reason;
        $purchase->save();

        $this->audit->log(
            'purchase.cancelled',
            $purchase,
            "Purchase {$purchase->reference} cancelled.",
            [
                'reason' => $reason,
                'already_received_value' => $purchase->receivedValue(),
            ],
        );

        return $purchase;
    }

    /** Only an untouched draft can actually be removed (#104, #198). */
    public function delete(Purchase $purchase): bool
    {
        if (! $purchase->canBeDeleted()) {
            return false;
        }

        $reference = $purchase->reference;

        DB::transaction(function () use ($purchase): void {
            $purchase->items()->delete();
            $purchase->delete();
        });

        $this->audit->log('purchase.deleted', $purchase, "Draft purchase {$reference} deleted.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * Rewrite a draft's lines and recompute the document's own arithmetic.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(Purchase $purchase, array $items): void
    {
        $keptIds = [];

        foreach (array_values($items) as $row) {
            $product = Product::find((int) ($row['product_id'] ?? 0));

            abort_if($product === null, 422, 'That product does not exist in this business.');

            $variantId = $this->resolveVariant($product, $row['product_variant_id'] ?? null);

            $quantity = round(max(0, (float) ($row['quantity_ordered'] ?? 0)), 4);

            abort_if($quantity <= 0, 422, "A line needs a quantity: \"{$product->name}\".");

            $item = filled($row['id'] ?? null)
                ? $purchase->items()->find((int) $row['id'])
                : null;

            $item ??= new PurchaseItem(['purchase_id' => $purchase->id]);

            $item->fill([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                // Snapshotted: a product renamed next year must not rewrite this
                // bill.
                'description' => $this->describe($product, $variantId, $row['description'] ?? null),
                'quantity_ordered' => $quantity,
                'unit_cost' => round(max(0, (float) ($row['unit_cost'] ?? $product->cost_price)), 4),
                'discount_amount' => round(max(0, (float) ($row['discount_amount'] ?? 0)), 2),
                'tax_rate' => round(max(0, (float) ($row['tax_rate'] ?? $product->tax_rate ?? 0)), 2),
                'batch_number' => filled($row['batch_number'] ?? null) ? trim((string) $row['batch_number']) : null,
                'expiry_date' => filled($row['expiry_date'] ?? null) ? $row['expiry_date'] : null,
                'notes' => $row['notes'] ?? null,
            ]);

            $item->line_total = $item->net();
            $item->save();

            $keptIds[] = $item->id;
        }

        // Lines dropped from the form go, since only a draft can be edited and a
        // draft has posted nothing.
        $purchase->items()->whereNotIn('id', $keptIds)->delete();

        $purchase->load('items');
        $this->recalculateTotals($purchase);
    }

    /**
     * The document's totals, from its lines.
     *
     * Stored rather than computed on read so an old bill keeps reading the way
     * it read when it was issued — see the migration docblock.
     */
    protected function recalculateTotals(Purchase $purchase): void
    {
        $purchase->subtotal = round($purchase->items->sum(fn (PurchaseItem $i) => $i->gross()), 2);
        $purchase->discount_total = round($purchase->items->sum(fn (PurchaseItem $i) => (float) $i->discount_amount), 2);
        $purchase->tax_total = round($purchase->items->sum(fn (PurchaseItem $i) => $i->taxAmount()), 2);
        $purchase->total = round($purchase->items->sum(fn (PurchaseItem $i) => $i->net()), 2);
        $purchase->save();
    }

    protected function describe(Product $product, ?int $variantId, ?string $given): string
    {
        if (filled($given)) {
            return (string) $given;
        }

        if ($variantId === null) {
            return $product->name;
        }

        $variant = $product->variants->firstWhere('id', $variantId)
            ?? ProductVariant::find($variantId);

        return $variant === null ? $product->name : $product->name.' — '.$variant->name;
    }

    protected function resolveVariant(Product $product, mixed $variantId): ?int
    {
        $product->loadMissing('variants');

        if ($product->hasVariants()) {
            abort_if(blank($variantId), 422, "\"{$product->name}\" has variants — say which one.");

            $belongs = $product->variants->contains('id', (int) $variantId);

            abort_unless($belongs, 422, 'That variant does not belong to this product.');

            return (int) $variantId;
        }

        return null;
    }

    protected function resolveSupplier(mixed $supplierId): Supplier
    {
        $supplier = Supplier::find((int) $supplierId);

        abort_if($supplier === null, 422, 'That supplier does not exist in this business.');

        return $supplier;
    }

    protected function resolveBranch(mixed $branchId): int
    {
        $branchId = $branchId !== null && $branchId !== ''
            ? (int) $branchId
            : (int) (Auth::guard('web')->user()?->branch_id ?? 0);

        abort_if($branchId === 0, 422, 'A purchase needs a branch to deliver to.');

        abort_unless($this->branches->allows($branchId), 403, 'That branch is outside your access.');

        return $branchId;
    }

    protected function nextReference(): string
    {
        $last = Purchase::query()->withTrashed()->orderByDesc('id')->value('reference');

        $number = $last !== null && preg_match('/(\d+)$/', $last, $m)
            ? ((int) $m[1]) + 1
            : 1;

        return 'PO-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    protected function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::PURCHASES_ORDERS)) {
            throw new FeatureUnavailableException(FeatureRegistry::PURCHASES_ORDERS, 'Purchases');
        }
    }
}
