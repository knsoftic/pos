<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sending goods back to a supplier (#37).
 *
 * A return posts everything at once, in one transaction: stock off the shelf,
 * supplier credited. There is no draft state, because unlike an order there is
 * nothing to wait for — either the goods went back or they did not.
 *
 * THE RULE THAT MATTERS: you cannot send back more than arrived. Every line
 * points at the purchase line it reverses, so the service can answer "12 came
 * in, 5 already went back, so 7 is the most you can return now". Without that
 * link a shop could return the same delivery twice and quietly put the
 * supplier's account the wrong way round.
 *
 * Stock leaves at the cost it came in at, not at today's cost — returning goods
 * should not change what the remaining stock is worth.
 */
class PurchaseReturnService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
        protected InventoryService $inventory,
        protected SupplierLedgerService $ledger,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  reason, return_date, notes
     * @param  array<int, float>  $quantities  purchase item id => quantity going back
     */
    public function create(Purchase $purchase, array $data, array $quantities): PurchaseReturn
    {
        $this->assertFeature();

        abort_unless(
            $purchase->status->hasPosted(),
            422,
            'Nothing has arrived on this purchase, so nothing can go back.',
        );

        abort_unless(
            $this->branches->allows($purchase->branch_id),
            403,
            'That branch is outside your access.',
        );

        abort_if(blank($data['reason'] ?? null), 422, 'Say why the goods are going back.');

        $purchase->loadMissing('items.product', 'supplier');

        // Work out what is actually going back BEFORE writing anything, so a
        // line that is over the limit stops the whole return rather than being
        // half-applied.
        $lines = [];

        foreach ($purchase->items as $item) {
            $quantity = round((float) ($quantities[$item->id] ?? 0), 4);

            if ($quantity <= 0) {
                continue;
            }

            $returnable = $item->returnableQuantity();

            abort_if(
                $quantity > $returnable + 0.00005,
                422,
                sprintf(
                    '"%s": %s arrived and %s already went back, so only %s can be returned.',
                    $item->description,
                    $this->trim((float) $item->quantity_received),
                    $this->trim($item->returnedQuantity()),
                    $this->trim($returnable),
                ),
            );

            $lines[] = ['item' => $item, 'quantity' => $quantity];
        }

        abort_if($lines === [], 422, 'Nothing was marked as going back.');

        return DB::transaction(function () use ($purchase, $data, $lines): PurchaseReturn {
            $return = new PurchaseReturn([
                'branch_id' => $purchase->branch_id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'reference' => $this->nextReference(),
                'return_date' => $data['return_date'] ?? now()->toDateString(),
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::guard('web')->id(),
            ]);
            $return->save();

            $subtotal = 0.0;
            $tax = 0.0;

            foreach ($lines as $line) {
                /** @var PurchaseItem $item */
                $item = $line['item'];
                $quantity = $line['quantity'];

                // The all-in unit value, so the credit matches what was billed.
                $unitValue = $item->effectiveUnitCost();
                $lineTotal = round($quantity * $unitValue, 2);

                $returnItem = new PurchaseReturnItem([
                    'purchase_return_id' => $return->id,
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'description' => $item->description,
                    'quantity' => $quantity,
                    'unit_cost' => (float) $item->unit_cost,
                    'tax_rate' => (float) $item->tax_rate,
                    'line_total' => $lineTotal,
                ]);
                $returnItem->save();

                // ---- stock off the shelf ---------------------------------
                if ($this->inventory->isTrackingEnabled() && $item->product?->tracksStock()) {
                    $this->inventory->createMovement([
                        'product' => $item->product_id,
                        'variant_id' => $item->product_variant_id,
                        'branch_id' => $purchase->branch_id,
                        'type' => StockMovementType::PurchaseReturn,
                        'quantity' => $quantity,
                        'reference' => $return,
                        'reason' => "Return {$return->reference} to ".$purchase->supplier?->name,
                    ]);
                }

                $subtotal = round($subtotal + ($quantity * (float) $item->unit_cost), 2);
                $tax = round($tax + ($lineTotal - ($quantity * (float) $item->unit_cost)), 2);
            }

            $return->subtotal = $subtotal;
            $return->tax_total = max(0, $tax);
            $return->total = round($return->items()->sum('line_total'), 2);
            $return->save();

            // ---- the supplier is credited --------------------------------
            $this->ledger->recordReturn($purchase->supplier, (float) $return->total, [
                'entry_date' => $return->return_date->toDateString(),
                'description' => "Return {$return->reference}: {$return->reason}",
                'reference' => $return,
                'reference_no' => $return->reference,
                'branch_id' => $purchase->branch_id,
            ]);

            $return->load('items');

            $this->audit->log(
                'purchase.returned',
                $return,
                sprintf(
                    'Return %s to %s against purchase %s.',
                    $return->reference,
                    $purchase->supplier?->name,
                    $purchase->reference,
                ),
                [
                    'reason' => $return->reason,
                    'quantity' => $return->totalQuantity(),
                    'value' => (float) $return->total,
                ],
            );

            return $return;
        });
    }

    protected function nextReference(): string
    {
        $last = PurchaseReturn::query()->orderByDesc('id')->value('reference');

        $number = $last !== null && preg_match('/(\d+)$/', $last, $m)
            ? ((int) $m[1]) + 1
            : 1;

        return 'PR-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    protected function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::PURCHASES_RETURNS)) {
            throw new FeatureUnavailableException(FeatureRegistry::PURCHASES_RETURNS, 'Purchase returns');
        }
    }
}
