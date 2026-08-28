<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moving stock between branches (#32).
 *
 * THE MODEL, and why it has three steps rather than one:
 *
 *   A transfer is a journey. Goods leave one shelf before they reach another,
 *   and during that window they are on neither. Posting a single "move" would
 *   make the van invisible — and when eleven boxes leave and ten arrive, there
 *   would be nowhere for the missing one to be recorded.
 *
 *   send()     → TransferOut at the source. Stock is now in transit.
 *   receive()  → TransferIn at the destination, for what ACTUALLY arrived.
 *
 *   A shortfall is therefore not reconciled: the ledger simply shows stock that
 *   left and never landed, and the transfer carries the discrepancy on its face.
 *   That is the honest record, and it is the only version that lets a shop ask
 *   who packed the van.
 *
 * Every stock change here goes through {@see InventoryService::createMovement()},
 * like every other module — this service decides WHEN and WHERE, never how.
 */
class StockTransferService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
        protected InventoryService $inventory,
        protected AuditService $audit,
    ) {}

    // ------------------------------------------------------------- the draft

    /**
     * @param  array{from_branch_id: int, to_branch_id: int, notes?: string|null}  $data
     * @param  list<array{product_id: int, variant_id?: int|null, quantity: float, notes?: string|null}>  $items
     *
     * @throws FeatureUnavailableException
     */
    public function create(array $data, array $items): StockTransfer
    {
        $this->assertFeature();

        $from = $this->resolveBranch($data['from_branch_id'], 'source');
        $to = $this->resolveBranch($data['to_branch_id'], 'destination');

        abort_if($from->id === $to->id, 422, 'A transfer needs two different branches.');

        // You may only send FROM a branch you can reach. Receiving is checked
        // separately, at receive time, because the other end is usually someone
        // else's job (#48).
        abort_unless($this->branches->allows($from->id), 403, 'That source branch is outside your access.');

        return DB::transaction(function () use ($from, $to, $data, $items): StockTransfer {
            $transfer = new StockTransfer([
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $transfer->reference = $this->nextReference();
            $transfer->status = TransferStatus::Draft;
            $transfer->created_by = Auth::guard('web')->id();
            $transfer->save();

            $this->syncItems($transfer, $items);

            $this->audit->log(
                'transfer.created',
                $transfer,
                "Transfer {$transfer->reference} drafted: {$from->name} → {$to->name}.",
                ['items' => count($items)],
            );

            return $transfer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function update(StockTransfer $transfer, array $data, array $items): StockTransfer
    {
        abort_unless($transfer->status->isEditable(), 422, 'Only a draft can be edited — this one has already moved stock.');
        abort_unless($this->branches->allows($transfer->from_branch_id), 403, 'That transfer is outside your access.');

        return DB::transaction(function () use ($transfer, $data, $items): StockTransfer {
            if (isset($data['to_branch_id'])) {
                $to = $this->resolveBranch((int) $data['to_branch_id'], 'destination');
                abort_if($to->id === $transfer->from_branch_id, 422, 'A transfer needs two different branches.');
                $transfer->to_branch_id = $to->id;
            }

            $transfer->notes = $data['notes'] ?? null;
            $transfer->save();

            $this->syncItems($transfer, $items);

            return $transfer;
        });
    }

    // -------------------------------------------------------------- the legs

    /**
     * The goods leave. Stock comes off the source shelf and is in transit.
     *
     * If any line has insufficient stock the whole thing rolls back —
     * {@see InventoryService} throws, and a half-sent transfer would leave the
     * source short by a quantity nobody agreed to.
     */
    public function send(StockTransfer $transfer): StockTransfer
    {
        $this->assertFeature();

        abort_unless($transfer->status->canBeSent(), 422, 'Only a draft can be sent.');
        abort_unless($this->branches->allows($transfer->from_branch_id), 403, 'That source branch is outside your access.');

        $transfer->load('items');

        abort_if($transfer->items->isEmpty(), 422, 'There is nothing on this transfer to send.');

        return DB::transaction(function () use ($transfer): StockTransfer {
            foreach ($transfer->items as $item) {
                $this->inventory->createMovement([
                    'product' => $item->product_id,
                    'variant_id' => $item->product_variant_id,
                    'branch_id' => $transfer->from_branch_id,
                    'type' => StockMovementType::TransferOut,
                    'quantity' => (float) $item->quantity_sent,
                    'reference' => $transfer,
                    'reason' => "Transfer {$transfer->reference} to ".$transfer->toBranch?->name,
                ]);
            }

            $transfer->status = TransferStatus::Sent;
            $transfer->sent_by = Auth::guard('web')->id();
            $transfer->sent_at = now();
            $transfer->save();

            $this->audit->log(
                'transfer.sent',
                $transfer,
                "Transfer {$transfer->reference} sent from ".$transfer->fromBranch?->name.'.',
                ['quantity' => $transfer->totalSent(), 'value' => $transfer->value()],
            );

            return $transfer;
        });
    }

    /**
     * The goods arrive and someone counts them.
     *
     * @param  array<int, float>  $counted  item id => quantity actually received.
     *                                      A line left out is taken as "all of
     *                                      it arrived", because that is the
     *                                      overwhelmingly common case and making
     *                                      people retype it invites typos.
     */
    public function receive(StockTransfer $transfer, array $counted = []): StockTransfer
    {
        $this->assertFeature();

        abort_unless($transfer->status->canBeReceived(), 422, 'Only a transfer in transit can be received.');
        abort_unless($this->branches->allows($transfer->to_branch_id), 403, 'That destination branch is outside your access.');

        $transfer->load('items');

        return DB::transaction(function () use ($transfer, $counted): StockTransfer {
            foreach ($transfer->items as $item) {
                $quantity = array_key_exists($item->id, $counted)
                    ? max(0.0, round((float) $counted[$item->id], 4))
                    : (float) $item->quantity_sent;

                $item->quantity_received = $quantity;
                $item->save();

                // Nothing arrived on this line: record the fact and post no
                // movement. The stock left the source and is simply gone.
                if ($quantity <= 0) {
                    continue;
                }

                $this->inventory->createMovement([
                    'product' => $item->product_id,
                    'variant_id' => $item->product_variant_id,
                    'branch_id' => $transfer->to_branch_id,
                    'type' => StockMovementType::TransferIn,
                    'quantity' => $quantity,
                    // The cost travels with the goods, so the receiving branch
                    // values them at what they actually cost.
                    'unit_cost' => (float) $item->unit_cost,
                    'reference' => $transfer,
                    'reason' => "Transfer {$transfer->reference} from ".$transfer->fromBranch?->name,
                ]);
            }

            $transfer->status = TransferStatus::Received;
            $transfer->received_by = Auth::guard('web')->id();
            $transfer->received_at = now();
            $transfer->save();

            $transfer->load('items');

            $this->audit->log(
                'transfer.received',
                $transfer,
                "Transfer {$transfer->reference} received at ".$transfer->toBranch?->name.'.',
                [
                    'sent' => $transfer->totalSent(),
                    'received' => $transfer->totalReceived(),
                    'shortfall' => $transfer->shortfall(),
                ],
            );

            return $transfer;
        });
    }

    /**
     * Call it off.
     *
     * A draft has moved nothing, so cancelling costs nothing. A sent transfer
     * has stock in transit, and that stock has to go somewhere — back where it
     * came from, posted as a movement like everything else, so the round trip
     * is visible in the source branch's ledger rather than being erased.
     */
    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        abort_unless($transfer->status->canBeCancelled(), 422, 'A received transfer is history — correct it with a stock adjustment instead.');

        /*
         | Who may cancel depends on where the goods are.
         |
         | A draft has moved nothing, so either end may call it off. Once it is
         | in transit the stock has to be put back on the SOURCE shelf, and only
         | someone who can reach that branch may write to it — otherwise the
         | movement would be refused mid-transaction and the cancel would fail
         | with a confusing error instead of an honest "not yours to cancel".
         */
        $allowed = $transfer->status->isInTransit()
            ? $this->branches->allows($transfer->from_branch_id)
            : ($this->branches->allows($transfer->from_branch_id)
                || $this->branches->allows($transfer->to_branch_id));

        abort_unless($allowed, 403, $transfer->status->isInTransit()
            ? 'Only the sending branch can cancel a transfer that is already on its way.'
            : 'That transfer is outside your access.');

        $transfer->load('items');

        return DB::transaction(function () use ($transfer, $reason): StockTransfer {
            if ($transfer->status->isInTransit()) {
                foreach ($transfer->items as $item) {
                    $this->inventory->createMovement([
                        'product' => $item->product_id,
                        'variant_id' => $item->product_variant_id,
                        'branch_id' => $transfer->from_branch_id,
                        'type' => StockMovementType::TransferIn,
                        'quantity' => (float) $item->quantity_sent,
                        'unit_cost' => (float) $item->unit_cost,
                        'reference' => $transfer,
                        'reason' => "Transfer {$transfer->reference} cancelled — returned to shelf",
                    ]);
                }
            }

            $transfer->status = TransferStatus::Cancelled;
            $transfer->cancelled_by = Auth::guard('web')->id();
            $transfer->cancelled_at = now();
            $transfer->cancellation_reason = $reason;
            $transfer->save();

            $this->audit->log(
                'transfer.cancelled',
                $transfer,
                "Transfer {$transfer->reference} cancelled.",
                ['reason' => $reason],
            );

            return $transfer;
        });
    }

    /** Only a draft can actually be removed; anything else is cancelled. */
    public function delete(StockTransfer $transfer): bool
    {
        if (! $transfer->canBeDeleted()) {
            return false;
        }

        abort_unless($this->branches->allows($transfer->from_branch_id), 403, 'That transfer is outside your access.');

        $reference = $transfer->reference;
        $transfer->delete();

        $this->audit->log('transfer.deleted', $transfer, "Draft transfer {$reference} deleted.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * Replace the draft's lines.
     *
     * Cost is snapshotted here from what the source shelf currently holds, so
     * the value that travels is the value that left — not whatever the
     * catalogue happens to say on the day it is received.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(StockTransfer $transfer, array $items): void
    {
        $transfer->items()->delete();

        $seen = [];

        foreach ($items as $row) {
            $quantity = round((float) ($row['quantity'] ?? 0), 4);

            if ($quantity <= 0) {
                continue;
            }

            $product = Product::find((int) ($row['product_id'] ?? 0));

            abort_if($product === null, 422, 'That product does not exist in this business.');
            abort_unless($product->tracksStock(), 422, "\"{$product->name}\" does not carry stock, so it cannot be transferred.");

            $variantId = $this->resolveVariantId($product, $row['variant_id'] ?? null);

            // The unique index would catch this, but a clear message beats a
            // database error the user cannot act on.
            $key = $product->id.':'.($variantId ?? 0);
            abort_if(in_array($key, $seen, true), 422, "\"{$product->name}\" is on this transfer twice — combine the lines.");
            $seen[] = $key;

            StockTransferItem::create([
                'stock_transfer_id' => $transfer->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'quantity_sent' => $quantity,
                'unit_cost' => $this->costOnShelf($transfer->from_branch_id, $product, $variantId),
                'notes' => $row['notes'] ?? null,
            ]);
        }

        $transfer->load('items');

        abort_if($transfer->items->isEmpty(), 422, 'A transfer needs at least one product.');
    }

    /**
     * What the source shelf says these goods cost. Falls back to the catalogue
     * when the shelf has no history, so stock never travels valued at zero.
     */
    protected function costOnShelf(int $branchId, Product $product, ?int $variantId): float
    {
        $average = Stock::query()
            ->allBranches()
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->when($variantId === null,
                fn ($q) => $q->whereNull('product_variant_id'),
                fn ($q) => $q->where('product_variant_id', $variantId),
            )
            ->value('average_cost');

        if ($average !== null && (float) $average > 0) {
            return (float) $average;
        }

        $catalogue = $variantId !== null
            ? (float) (ProductVariant::query()->whereKey($variantId)->value('cost_price') ?? 0)
            : (float) $product->cost_price;

        return round(max(0, $catalogue), 4);
    }

    protected function resolveVariantId(Product $product, mixed $variantId): ?int
    {
        if ($product->hasVariants()) {
            abort_if(blank($variantId), 422, "\"{$product->name}\" has variants — say which one.");

            $belongs = ProductVariant::query()
                ->whereKey((int) $variantId)
                ->where('product_id', $product->id)
                ->exists();

            abort_unless($belongs, 422, 'That variant does not belong to this product.');

            return (int) $variantId;
        }

        return null;
    }

    protected function resolveBranch(int $branchId, string $label): Branch
    {
        $branch = Branch::find($branchId);

        abort_if($branch === null, 422, "That {$label} branch does not exist in this business.");

        return $branch;
    }

    /**
     * TRF-000001, per business.
     *
     * Read inside the caller's transaction so two people drafting at the same
     * moment cannot both take the same number; the unique index is the backstop
     * if they somehow do.
     */
    protected function nextReference(): string
    {
        $last = StockTransfer::query()
            ->orderByDesc('id')
            ->value('reference');

        $number = $last !== null && preg_match('/(\d+)$/', $last, $m)
            ? ((int) $m[1]) + 1
            : 1;

        return 'TRF-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::INVENTORY_TRANSFERS)) {
            throw new FeatureUnavailableException(
                FeatureRegistry::INVENTORY_TRANSFERS,
                'Stock transfers',
            );
        }
    }
}
