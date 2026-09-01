<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Goods coming back from a customer (#53).
 *
 * ================= WHAT A RETURN ACTUALLY DOES =================
 * Four things, in one transaction, or none of them:
 *
 *   1. Records what came back, against the sale lines it reverses.
 *   2. Puts the sellable goods BACK ON THE SHELF — and leaves the rest off it.
 *   3. Gives the money back: refunded out of the drawer, credited to the
 *      account, or some of each.
 *   4. Reverses the profit, at the cost that applied WHEN IT SOLD.
 *
 * ================= THE DECISION THAT MATTERS MOST =================
 * RESTOCKING IS PER LINE. A customer returning an unopened box and one
 * returning a smashed one are both owed their money, but only one of those goes
 * back on the shelf. Restocking everything by default would inflate stock every
 * time something came back broken, and the shop would discover it at the next
 * count with no way to trace it.
 *
 * ================= WHERE THE MONEY GOES =================
 * A walk-in can only be refunded — there is no account to credit. An account
 * customer can be either, and the shop decides at the counter: crediting
 * somebody who already owes money is usually what both sides want, and handing
 * cash to someone with an unpaid balance rarely is. The caller says which; this
 * service refuses only the impossible combination.
 */
class SaleReturnService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
        protected InventoryService $inventory,
        protected CustomerLedgerService $customerLedger,
        protected CashSessionService $cashSessions,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{reason: string, return_date?: string|null, notes?: string|null,
     *               refund_amount?: float|null, refund_method?: string|null,
     *               credit_amount?: float|null}  $data
     * @param  array<int, array{quantity: float, restock?: bool, condition_note?: string|null}>  $lines
     *                                                                                                   keyed by sale item id
     */
    public function create(Sale $sale, array $data, array $lines): SaleReturn
    {
        $this->assertFeature();

        abort_unless(
            $sale->status === SaleStatus::Completed,
            422,
            'Only a completed sale can be returned against.',
        );

        abort_unless(
            $this->branches->allows($sale->branch_id),
            403,
            'That branch is outside your access.',
        );

        abort_if(blank($data['reason'] ?? null), 422, 'Say why the goods are coming back.');

        $sale->loadMissing(['items.product', 'items.returnItems', 'customer']);

        // Work out what is coming back BEFORE writing anything, so a line over
        // the limit stops the whole return rather than half-applying it.
        $prepared = [];
        $total = 0.0;
        $subtotal = 0.0;
        $tax = 0.0;
        $cost = 0.0;

        foreach ($sale->items as $item) {
            $row = $lines[$item->id] ?? null;

            if ($row === null) {
                continue;
            }

            $quantity = round((float) ($row['quantity'] ?? 0), 4);

            if ($quantity <= 0) {
                continue;
            }

            $returnable = $item->returnableQuantity();

            abort_if(
                $quantity > $returnable + 0.00005,
                422,
                sprintf(
                    '"%s": %s were sold and %s already came back, so only %s can be returned.',
                    $item->description,
                    $this->trim((float) $item->quantity),
                    $this->trim($item->returnedQuantity()),
                    $this->trim($returnable),
                ),
            );

            // The line's own all-in unit price, so returning half a discounted
            // line gives back half the discounted price.
            $unitValue = $item->effectiveUnitPrice();
            $lineTotal = round($quantity * $unitValue, 2);

            // Split back out so the return's own subtotal and tax add up the way
            // the sale's did.
            $lineTax = (float) $item->tax_rate > 0
                ? round($lineTotal - ($lineTotal / (1 + ((float) $item->tax_rate / 100))), 2)
                : 0.0;

            $prepared[] = [
                'item' => $item,
                'quantity' => $quantity,
                'unit_value' => $unitValue,
                'line_total' => $lineTotal,
                'restock' => (bool) ($row['restock'] ?? true),
                'condition_note' => $row['condition_note'] ?? null,
            ];

            $total = round($total + $lineTotal, 2);
            $tax = round($tax + $lineTax, 2);
            $subtotal = round($subtotal + ($lineTotal - $lineTax), 2);
            $cost = round($cost + ($quantity * (float) $item->unit_cost), 4);
        }

        abort_if($prepared === [], 422, 'Nothing was marked as coming back.');

        $settlement = $this->resolveSettlement($sale, $data, $total);

        return DB::transaction(function () use ($sale, $data, $prepared, $settlement, $total, $subtotal, $tax, $cost): SaleReturn {
            $user = Auth::guard('web')->user();
            $session = $this->cashSessions->currentFor($sale->branch_id, $sale->pos_counter_id);

            $return = new SaleReturn([
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'cash_session_id' => $session?->id,
                'reference' => $this->nextReference(),
                'return_date' => $data['return_date'] ?? now()->toDateString(),
                'reason' => $data['reason'],
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'total' => $total,
                'cost_total' => $cost,
                'refunded_amount' => $settlement['refund'],
                'credited_amount' => $settlement['credit'],
                'refund_method' => $settlement['refund'] > 0 ? $settlement['method'] : null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
            ]);
            $return->save();

            foreach ($prepared as $line) {
                /** @var SaleItem $item */
                $item = $line['item'];

                $returnItem = new SaleReturnItem([
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'description' => $item->description,
                    'quantity' => $line['quantity'],
                    'unit_price' => round($line['unit_value'], 2),
                    // From the SALE's snapshot: the profit reversal must use the
                    // cost that applied when it sold (#52).
                    'unit_cost' => (float) $item->unit_cost,
                    'tax_rate' => (float) $item->tax_rate,
                    'line_total' => $line['line_total'],
                    'restock' => $line['restock'],
                    'condition_note' => $line['condition_note'],
                ]);
                $returnItem->save();

                // ---- back on the shelf, but only if it is fit to sell -----
                if (! $line['restock']) {
                    continue;
                }

                if (! $this->inventory->isTrackingEnabled() || ! $item->product?->tracksStock()) {
                    continue;
                }

                $this->inventory->createMovement([
                    'product' => $item->product_id,
                    'variant_id' => $item->product_variant_id,
                    'branch_id' => $sale->branch_id,
                    'type' => StockMovementType::SaleReturn,
                    'quantity' => $line['quantity'],
                    // Back at what it cost, so returning goods does not change
                    // what the remaining stock is worth.
                    'unit_cost' => (float) $item->unit_cost,
                    'reference' => $return,
                    'reason' => "Return {$return->reference} against {$sale->invoice_no}",
                ]);
            }

            // ---- the money -------------------------------------------------
            if ($settlement['credit'] > 0 && $sale->customer !== null) {
                $this->customerLedger->recordReturn($sale->customer, $settlement['credit'], [
                    'description' => "Return {$return->reference} against {$sale->invoice_no}",
                    'reference' => $return,
                    'reference_no' => $return->reference,
                    'branch_id' => $sale->branch_id,
                    'entry_date' => $return->return_date->toDateString(),
                ]);
            }

            // A cash refund takes money OUT of the drawer, so the till's
            // expected figure has to move with it (#46).
            if ($settlement['refund'] > 0 && $this->isCashMethod($settlement['method'])) {
                $this->applyRefundToSession($session, $settlement['refund']);
            }

            $return->load('items');

            $this->audit->log(
                'sale.returned',
                $return,
                sprintf(
                    'Return %s against %s: %s.',
                    $return->reference,
                    $sale->invoice_no,
                    $return->settlementLabel(),
                ),
                [
                    'reason' => $return->reason,
                    'quantity' => $return->totalQuantity(),
                    'restocked' => $return->restockedQuantity(),
                    'written_off' => $return->writtenOffQuantity(),
                    'value' => (float) $return->total,
                ],
            );

            return $return;
        });
    }

    // ------------------------------------------------------------- internals

    /**
     * Decide how the money goes back.
     *
     * Defaults are chosen to be the least surprising: an account customer is
     * credited, a walk-in is refunded. Anything explicitly asked for is honoured
     * as long as it is possible and adds up.
     *
     * @param  array<string, mixed>  $data
     * @return array{refund: float, credit: float, method: string}
     */
    protected function resolveSettlement(Sale $sale, array $data, float $total): array
    {
        $method = (string) ($data['refund_method'] ?? 'cash');

        $refundAsked = array_key_exists('refund_amount', $data) && $data['refund_amount'] !== null;
        $creditAsked = array_key_exists('credit_amount', $data) && $data['credit_amount'] !== null;

        if (! $refundAsked && ! $creditAsked) {
            // Nothing said: credit an account customer, refund a walk-in.
            return $sale->customer_id !== null
                ? ['refund' => 0.0, 'credit' => $total, 'method' => $method]
                : ['refund' => $total, 'credit' => 0.0, 'method' => $method];
        }

        $refund = round(max(0, (float) ($data['refund_amount'] ?? 0)), 2);
        $credit = round(max(0, (float) ($data['credit_amount'] ?? 0)), 2);

        // Only one figure given: the rest goes the other way, so the customer is
        // never left short by a form that only asked one question.
        if ($refundAsked && ! $creditAsked) {
            $credit = round(max(0, $total - $refund), 2);
        } elseif ($creditAsked && ! $refundAsked) {
            $refund = round(max(0, $total - $credit), 2);
        }

        abort_if(
            abs(round($refund + $credit, 2) - $total) > 0.005,
            422,
            sprintf(
                'The refund and the credit come to %s, but the return is worth %s.',
                number_format($refund + $credit, 2),
                number_format($total, 2),
            ),
        );

        abort_if(
            $credit > 0 && $sale->customer_id === null,
            422,
            'A walk-in has no account to credit — the money has to be handed back.',
        );

        return ['refund' => $refund, 'credit' => $credit, 'method' => $method];
    }

    protected function isCashMethod(string $method): bool
    {
        return in_array($method, (array) config('pos.cash_methods', ['cash']), true);
    }

    protected function applyRefundToSession(?CashSession $session, float $amount): void
    {
        if ($session === null) {
            return;
        }

        $locked = CashSession::query()->allBranches()->whereKey($session->id)->lockForUpdate()->first();

        if ($locked === null) {
            return;
        }

        $locked->cash_refunds = round((float) $locked->cash_refunds + $amount, 2);
        $locked->save();
    }

    protected function nextReference(): string
    {
        $last = SaleReturn::query()->allBranches()->orderByDesc('id')->value('reference');

        $number = $last !== null && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1]) + 1 : 1;

        return 'RET-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    protected function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::SALES_RETURNS)) {
            throw new FeatureUnavailableException(FeatureRegistry::SALES_RETURNS, 'Sale returns');
        }
    }
}
