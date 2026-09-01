<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Stock;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Selling (#118, #184). The busiest write path in the whole system.
 *
 * ================= THE SIXTEEN STEPS (#118) =================
 * All of them inside ONE transaction, so a till either completes a sale or
 * changes nothing at all. Half a sale — stock gone but no invoice, or an invoice
 * with no stock movement — is the worst outcome available, because the shop
 * cannot tell which half happened.
 *
 *   1. Validate there is something to sell.
 *   2. Resolve the branch, the till and the cash session.
 *   3. Resolve the customer (or walk-in) and lock them if credit is involved.
 *   4. Build the lines: resolve each product/variant against this tenant.
 *   5. Snapshot description, price AND cost onto each line (#52).
 *   6. Total the document: subtotal, discounts, tax, rounding.
 *   7. Validate the tenders — split payments must add up (#19).
 *   8. ── open the transaction ──
 *   9. Allocate the invoice number (#22).
 *  10. Write the sale header.
 *  11. Write the lines.
 *  12. Take the stock, ROW BY ROW UNDER LOCK (#70).
 *  13. Record the payments (#17, #19).
 *  14. Charge any remainder to the customer's account (#40, #41).
 *  15. Move the till's cash figure (#46).
 *  16. Audit, and commit.
 *
 * ================= WHY STEP 12 IS WHERE IT IS =================
 * Stock is taken INSIDE the transaction, through {@see InventoryService}, which
 * locks each shelf row. That lock is the whole of the race protection (#70): two
 * tills selling the last unit queue on the same row, and the second one is told
 * there is nothing left instead of both succeeding. Checking availability before
 * the transaction and deducting after would leave exactly the gap the lock
 * closes.
 *
 * ================= CHANGE, AND WHAT A PAYMENT MEANS =================
 * `sale_payments.amount` is what was APPLIED to the sale, not what was handed
 * over. Paying 1,000 in cash for an 850 sale records an 850 cash payment and 150
 * of change. That keeps `paid_total` equal to the total, and keeps the drawer
 * arithmetic honest — the till gained 1,000 and gave back 150, which is the same
 * 850.
 */
class SaleService
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

    // ------------------------------------------------------------ the sale

    /**
     * Complete a sale.
     *
     * @param  array<string, mixed>  $data  customer_id, branch_id, pos_counter_id,
     *                                      notes, sale_date
     * @param  list<array<string, mixed>>  $lines  product_id, variant_id, quantity,
     *                                             unit_price, discount_amount, tax_rate
     * @param  list<array<string, mixed>>  $payments  method, amount, reference
     */
    public function complete(array $data, array $lines, array $payments = []): Sale
    {
        $this->assertFeature();

        // 1 ── something to sell
        abort_if($lines === [], 422, 'There is nothing in the cart.');

        // 2 ── where
        $branchId = $this->resolveBranch($data['branch_id'] ?? null);
        $counterId = $data['pos_counter_id'] ?? Auth::guard('web')->user()?->pos_counter_id;
        $session = $this->resolveSession($branchId, $counterId);

        // 3 ── who
        $customer = $this->resolveCustomer($data['customer_id'] ?? null);

        // 4 & 5 ── the lines, with prices and costs snapshotted
        $prepared = $this->prepareLines($lines, $branchId);

        // 6 ── the document's arithmetic
        $totals = $this->totalise($prepared);

        // 7 ── the tenders
        $tenders = $this->prepareTenders($payments, $totals['total'], $customer);

        // 8 ── one transaction for everything that follows
        return DB::transaction(function () use ($data, $prepared, $totals, $tenders, $branchId, $counterId, $session, $customer): Sale {
            $user = Auth::guard('web')->user();

            // 9 & 10 ── the invoice number and the header
            $sale = new Sale([
                'branch_id' => $branchId,
                'pos_counter_id' => $counterId,
                'cash_session_id' => $session?->id,
                'customer_id' => $customer?->id,
                'invoice_no' => $this->nextInvoiceNumber($branchId),
                'status' => SaleStatus::Completed,
                'sold_at' => now(),
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'rounding' => $totals['rounding'],
                'total' => $totals['total'],
                'cost_total' => $totals['cost_total'],
                'paid_total' => $tenders['applied'],
                'change_given' => $tenders['change'],
                'due_amount' => $tenders['on_credit'],
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'notes' => $data['notes'] ?? null,
            ]);
            $sale->save();

            // 11 ── the lines
            foreach ($prepared as $line) {
                $item = new SaleItem(array_merge($line['attributes'], ['sale_id' => $sale->id]));
                $item->save();
            }

            // 12 ── the stock, under lock (#70)
            $this->takeStock($sale, $prepared, $branchId);

            // 13 ── the money handed over
            foreach ($tenders['payments'] as $tender) {
                $payment = new SalePayment([
                    'sale_id' => $sale->id,
                    'method' => $tender['method'],
                    'amount' => $tender['amount'],
                    'reference' => $tender['reference'] ?? null,
                    'received_at' => now(),
                    'user_id' => $user?->id,
                ]);
                $payment->save();
            }

            // 14 ── whatever is left goes on the account (#40, #41)
            if ($tenders['on_credit'] > 0 && $customer !== null) {
                $this->customerLedger->chargeSale($customer, $tenders['on_credit'], [
                    'description' => "Sale {$sale->invoice_no}",
                    'reference' => $sale,
                    'reference_no' => $sale->invoice_no,
                    'branch_id' => $branchId,
                ]);
            }

            // 15 ── the drawer (#46)
            $this->applyToSession($session, $tenders['cash'], 0.0);

            // 16 ── the record of who did what
            $sale->load(['items', 'payments']);

            $this->audit->log(
                'sale.completed',
                $sale,
                sprintf(
                    'Sale %s: %s to %s.',
                    $sale->invoice_no,
                    number_format((float) $sale->total, 2),
                    $sale->customerName(),
                ),
                [
                    'total' => (float) $sale->total,
                    'lines' => $sale->items->count(),
                    'on_credit' => $tenders['on_credit'],
                    'methods' => array_column($tenders['payments'], 'method'),
                ],
            );

            return $sale;
        });
    }

    /**
     * Park a sale mid-transaction (#20).
     *
     * Posts NOTHING: no stock moves, nobody owes anything, and the invoice
     * number is not spent. A customer who went back for the milk they forgot has
     * not bought anything yet.
     */
    public function hold(array $data, array $lines): Sale
    {
        $this->assertFeature();

        abort_if($lines === [], 422, 'There is nothing to hold.');

        $branchId = $this->resolveBranch($data['branch_id'] ?? null);
        $prepared = $this->prepareLines($lines, $branchId, checkStock: false);
        $totals = $this->totalise($prepared);
        $customer = $this->resolveCustomer($data['customer_id'] ?? null);

        return DB::transaction(function () use ($data, $prepared, $totals, $branchId, $customer): Sale {
            $user = Auth::guard('web')->user();

            $sale = new Sale([
                'branch_id' => $branchId,
                'pos_counter_id' => $data['pos_counter_id'] ?? $user?->pos_counter_id,
                'customer_id' => $customer?->id,
                // A held sale still needs a handle to call it back by, but it
                // must not consume an invoice number — those are sequential and
                // a gap is a question a tax inspector asks.
                'invoice_no' => $this->nextHoldReference(),
                'status' => SaleStatus::Held,
                'sale_date' => now()->toDateString(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'rounding' => $totals['rounding'],
                'total' => $totals['total'],
                'cost_total' => $totals['cost_total'],
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'notes' => $data['notes'] ?? null,
            ]);
            $sale->save();

            foreach ($prepared as $line) {
                (new SaleItem(array_merge($line['attributes'], ['sale_id' => $sale->id])))->save();
            }

            return $sale;
        });
    }

    /** Bring a held sale back to the till, and let it be completed properly. */
    public function resume(Sale $sale, array $payments = [], array $data = []): Sale
    {
        abort_unless($sale->status === SaleStatus::Held, 422, 'Only a held sale can be resumed.');

        $sale->load('items');

        $lines = $sale->items->map(fn (SaleItem $item) => [
            'product_id' => $item->product_id,
            'variant_id' => $item->product_variant_id,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'discount_amount' => (float) $item->discount_amount,
            'tax_rate' => (float) $item->tax_rate,
        ])->all();

        $completed = $this->complete(array_merge([
            'customer_id' => $sale->customer_id,
            'branch_id' => $sale->branch_id,
            'pos_counter_id' => $sale->pos_counter_id,
            'notes' => $sale->notes,
        ], $data), $lines, $payments);

        // The held copy has served its purpose. It posted nothing, so there is
        // nothing to keep — unlike a completed sale, which is never deleted.
        DB::transaction(function () use ($sale): void {
            $sale->items()->delete();
            $sale->delete();
        });

        return $completed;
    }

    /**
     * Void a completed sale (#198).
     *
     * The record STAYS. The postings are reversed: stock goes back on the shelf
     * as its own movement, any credit charge is credited back, and the drawer
     * figure is corrected. Nothing is erased, because an invoice that existed
     * has to keep existing — somebody has the paper copy.
     */
    public function void(Sale $sale, string $reason): Sale
    {
        abort_unless($sale->status->canBeVoided(), 422, 'Only a completed sale can be voided.');
        abort_if(blank($reason), 422, 'Say why the sale is being voided.');

        $sale->load(['items.product', 'payments', 'customer', 'cashSession']);

        return DB::transaction(function () use ($sale, $reason): Sale {
            // ---- stock back on the shelf -------------------------------
            foreach ($sale->items as $item) {
                if (! $this->inventory->isTrackingEnabled() || ! $item->product?->tracksStock()) {
                    continue;
                }

                $this->inventory->createMovement([
                    'product' => $item->product_id,
                    'variant_id' => $item->product_variant_id,
                    'branch_id' => $sale->branch_id,
                    'type' => StockMovementType::SaleReturn,
                    'quantity' => (float) $item->quantity,
                    'unit_cost' => (float) $item->unit_cost,
                    'reference' => $sale,
                    'reason' => "Void of sale {$sale->invoice_no}: {$reason}",
                ]);
            }

            // ---- the customer's account --------------------------------
            if ((float) $sale->due_amount > 0 && $sale->customer !== null) {
                $this->customerLedger->recordReturn($sale->customer, (float) $sale->due_amount, [
                    'description' => "Void of sale {$sale->invoice_no}",
                    'reference' => $sale,
                    'reference_no' => $sale->invoice_no,
                    'branch_id' => $sale->branch_id,
                ]);
            }

            // ---- the drawer ---------------------------------------------
            $this->applyToSession($sale->cashSession, 0.0, $sale->cashTaken());

            $sale->status = SaleStatus::Voided;
            $sale->voided_at = now();
            $sale->void_reason = $reason;
            $sale->voided_by = Auth::guard('web')->id();
            $sale->save();

            $this->audit->log(
                'sale.voided',
                $sale,
                "Sale {$sale->invoice_no} voided.",
                [
                    'reason' => $reason,
                    'total' => (float) $sale->total,
                    'stock_returned' => $sale->totalQuantity(),
                ],
            );

            return $sale;
        });
    }

    /** Discard a held sale. Nothing was posted, so nothing is reversed. */
    public function discardHold(Sale $sale): bool
    {
        if (! $sale->status->canBeDeleted()) {
            return false;
        }

        DB::transaction(function () use ($sale): void {
            $sale->items()->delete();
            $sale->delete();
        });

        return true;
    }

    /** Record that an invoice was printed again (#143). */
    public function recordReprint(Sale $sale): Sale
    {
        $sale->increment('print_count');

        $this->audit->log(
            'sale.reprinted',
            $sale,
            "Invoice {$sale->invoice_no} reprinted.",
            ['print_count' => $sale->print_count + 1],
        );

        return $sale;
    }

    // -------------------------------------------------------- the arithmetic

    /**
     * Steps 4 and 5: resolve each line and snapshot what it is worth.
     *
     * The COST is captured here, from the shelf the goods are coming off, not
     * from the catalogue — the catalogue holds a nominal cost, the shelf holds
     * what this stock actually cost (#52).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{attributes: array<string, mixed>, product: Product, quantity: float}>
     */
    protected function prepareLines(array $lines, int $branchId, bool $checkStock = true): array
    {
        $prepared = [];

        foreach ($lines as $row) {
            $product = Product::find((int) ($row['product_id'] ?? 0));

            abort_if($product === null, 422, 'That product does not exist in this business.');
            abort_unless($product->is_active, 422, "\"{$product->name}\" is not on sale.");

            $variantId = $this->resolveVariant($product, $row['variant_id'] ?? $row['product_variant_id'] ?? null);
            $quantity = round((float) ($row['quantity'] ?? 0), 4);

            abort_if($quantity <= 0, 422, "A line needs a quantity: \"{$product->name}\".");

            $variant = $variantId === null ? null : $product->variants->firstWhere('id', $variantId);

            $unitPrice = array_key_exists('unit_price', $row) && $row['unit_price'] !== null && $row['unit_price'] !== ''
                ? round(max(0, (float) $row['unit_price']), 2)
                : round((float) ($variant?->selling_price ?? $product->selling_price), 2);

            $item = new SaleItem([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'description' => $variant === null ? $product->name : $product->name.' — '.$variant->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                // What these goods actually cost, from the shelf.
                'unit_cost' => $this->shelfCost($product, $variantId, $branchId, $variant?->cost_price),
                'discount_amount' => round(max(0, (float) ($row['discount_amount'] ?? 0)), 2),
                'tax_rate' => round(max(0, (float) ($row['tax_rate'] ?? $product->tax_rate ?? 0)), 2),
            ]);

            $item->line_total = $item->net();

            $prepared[] = [
                'attributes' => $item->attributesToArray(),
                'product' => $product,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'unit_cost' => (float) $item->unit_cost,
            ];
        }

        return $prepared;
    }

    /**
     * Step 6: the document's own arithmetic.
     *
     * @param  list<array<string, mixed>>  $prepared
     * @return array{subtotal: float, discount_total: float, tax_total: float, rounding: float, total: float, cost_total: float}
     */
    protected function totalise(array $prepared): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $net = 0.0;
        $cost = 0.0;

        foreach ($prepared as $line) {
            $item = new SaleItem($line['attributes']);

            $subtotal = round($subtotal + $item->gross(), 2);
            $discount = round($discount + (float) $item->discount_amount, 2);
            $tax = round($tax + $item->taxAmount(), 2);
            $net = round($net + $item->net(), 2);
            $cost = round($cost + $item->costValue(), 4);
        }

        // Rounding to the smallest coin still in circulation (#POS). Kept as its
        // own figure so a receipt can show it rather than burying it in a total
        // that then fails to add up.
        $rounded = $this->applyCashRounding($net);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'rounding' => round($rounded - $net, 2),
            'total' => $rounded,
            'cost_total' => $cost,
        ];
    }

    /**
     * Step 7: check the tenders add up (#17, #19).
     *
     * A split payment must cover the total between them. Anything short goes on
     * the customer's account, which needs a customer — a walk-in cannot owe
     * money, because there is nobody to ask for it.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return array{payments: list<array<string, mixed>>, applied: float, change: float, cash: float, on_credit: float}
     */
    protected function prepareTenders(array $payments, float $total, ?Customer $customer): array
    {
        $creditMethod = (string) config('pos.credit_method', 'credit');
        $allowed = (array) config('pos.payment_methods', []);
        $cashMethods = (array) config('pos.cash_methods', ['cash']);

        $tendered = 0.0;
        $onCredit = 0.0;
        $clean = [];

        foreach ($payments as $tender) {
            $method = (string) ($tender['method'] ?? '');
            $amount = round((float) ($tender['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            abort_unless(
                in_array($method, $allowed, true),
                422,
                "\"{$method}\" is not one of this shop's payment methods.",
            );

            if ($method === $creditMethod) {
                $onCredit = round($onCredit + $amount, 2);

                continue;
            }

            $tendered = round($tendered + $amount, 2);
            $clean[] = [
                'method' => $method,
                'amount' => $amount,
                'reference' => $tender['reference'] ?? null,
            ];
        }

        // What is still unpaid after the money handed over goes on account.
        $shortfall = round($total - $tendered - $onCredit, 2);

        if ($shortfall > 0.005) {
            $onCredit = round($onCredit + $shortfall, 2);
        }

        if ($onCredit > 0.005) {
            abort_if(
                $customer === null,
                422,
                'A sale on account needs a customer — a walk-in has nobody to bill.',
            );
        }

        // Change: cash handed over beyond what was owed. The payments recorded
        // are what was APPLIED, so the excess is trimmed off the last cash
        // tender rather than being recorded as money the shop kept.
        $applied = round(min($tendered, max(0, $total - $onCredit)), 2);
        $change = round(max(0, $tendered - $applied), 2);

        if ($change > 0) {
            $clean = $this->trimChange($clean, $change, $cashMethods);
        }

        $cash = 0.0;

        foreach ($clean as $tender) {
            if (in_array($tender['method'], $cashMethods, true)) {
                $cash = round($cash + $tender['amount'], 2);
            }
        }

        return [
            'payments' => $clean,
            'applied' => $applied,
            'change' => $change,
            'cash' => $cash,
            'on_credit' => max(0, $onCredit),
        ];
    }

    /**
     * Take the change out of the cash tenders, largest last.
     *
     * Change always comes out of cash — a shop does not hand back part of a card
     * payment — so a non-cash tender is never trimmed.
     *
     * @param  list<array<string, mixed>>  $tenders
     * @param  list<string>  $cashMethods
     * @return list<array<string, mixed>>
     */
    protected function trimChange(array $tenders, float $change, array $cashMethods): array
    {
        $remaining = $change;

        for ($i = count($tenders) - 1; $i >= 0 && $remaining > 0.005; $i--) {
            if (! in_array($tenders[$i]['method'], $cashMethods, true)) {
                continue;
            }

            $take = min($remaining, $tenders[$i]['amount']);
            $tenders[$i]['amount'] = round($tenders[$i]['amount'] - $take, 2);
            $remaining = round($remaining - $take, 2);
        }

        // A tender trimmed to nothing is not a payment that happened.
        return array_values(array_filter($tenders, fn ($t) => $t['amount'] > 0.005));
    }

    /**
     * Step 12: take the stock, under lock (#70).
     *
     * Batch-tracked products go through `issue()`, which walks the batches
     * first-expiry-first-out and writes one ledger line per batch (#34).
     *
     * @param  list<array<string, mixed>>  $prepared
     */
    protected function takeStock(Sale $sale, array $prepared, int $branchId): void
    {
        if (! $this->inventory->isTrackingEnabled()) {
            return;
        }

        foreach ($prepared as $line) {
            /** @var Product $product */
            $product = $line['product'];

            if (! $product->tracksStock()) {
                continue;
            }

            $movement = [
                'product' => $product->id,
                'variant_id' => $line['variant_id'],
                'branch_id' => $branchId,
                'type' => StockMovementType::Sale,
                'quantity' => $line['quantity'],
                'reference' => $sale,
                'reason' => "Sale {$sale->invoice_no}",
            ];

            // FEFO when the product carries batches; a plain movement otherwise.
            if ($this->inventory->tracksBatches($product)) {
                $this->inventory->issue($movement);

                continue;
            }

            $this->inventory->createMovement($movement);
        }
    }

    /** Step 15: move the drawer figure, in the same transaction as the sale. */
    protected function applyToSession(?CashSession $session, float $cashIn, float $cashOut): void
    {
        if ($session === null || ($cashIn <= 0 && $cashOut <= 0)) {
            return;
        }

        $locked = CashSession::query()->allBranches()->whereKey($session->id)->lockForUpdate()->first();

        if ($locked === null) {
            return;
        }

        if ($cashIn > 0) {
            $locked->cash_sales = round((float) $locked->cash_sales + $cashIn, 2);
        }

        if ($cashOut > 0) {
            $locked->cash_refunds = round((float) $locked->cash_refunds + $cashOut, 2);
        }

        $locked->save();
    }

    // ------------------------------------------------------------- internals

    /**
     * What the goods coming off this shelf actually cost.
     *
     * The shelf's weighted average, falling back to the catalogue when nothing
     * has ever been received — a product sold before it was ever bought in has
     * no better answer available.
     */
    protected function shelfCost(Product $product, ?int $variantId, int $branchId, mixed $variantCost = null): float
    {
        $stock = Stock::query()
            ->allBranches()
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->when($variantId === null,
                fn ($q) => $q->whereNull('product_variant_id'),
                fn ($q) => $q->where('product_variant_id', $variantId),
            )
            ->first();

        if ($stock !== null && (float) $stock->average_cost > 0) {
            return round((float) $stock->average_cost, 4);
        }

        return round((float) ($variantCost ?? $product->cost_price), 4);
    }

    protected function applyCashRounding(float $amount): float
    {
        $step = (float) config('pos.cash_rounding', 0);

        if ($step <= 0) {
            return round($amount, 2);
        }

        return round(round($amount / $step) * $step, 2);
    }

    /**
     * Step 9: mint the invoice number from the configured format (#22).
     *
     * The sequence is read under the transaction that is about to use it, and
     * the unique index is the backstop: if two tills mint the same number, the
     * second insert fails rather than two sales sharing an invoice.
     */
    public function nextInvoiceNumber(?int $branchId = null): string
    {
        $format = (string) config('pos.invoice.format', '{PREFIX}-{YYYY}{MM}-{SEQ:5}');
        $scope = (string) config('pos.invoice.sequence_scope', 'business');

        $query = Sale::query()->allBranches()->where('status', '!=', SaleStatus::Held);

        if ($scope === 'branch' && $branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        if ($scope === 'monthly') {
            $query->whereYear('sold_at', now()->year)->whereMonth('sold_at', now()->month);
        }

        $sequence = $query->count() + 1;

        $branchCode = $branchId === null
            ? ''
            : (string) (Branch::query()->whereKey($branchId)->value('code') ?? '');

        $number = str_replace(
            ['{PREFIX}', '{YYYY}', '{YY}', '{MM}', '{DD}', '{BRANCH}'],
            [
                (string) config('pos.invoice.prefix', 'INV'),
                now()->format('Y'),
                now()->format('y'),
                now()->format('m'),
                now()->format('d'),
                $branchCode,
            ],
            $format,
        );

        // {SEQ} or {SEQ:n}
        $number = preg_replace_callback(
            '/\{SEQ(?::(\d+))?\}/',
            fn (array $m) => isset($m[1])
                ? str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT)
                : (string) $sequence,
            $number,
        );

        // A format that produced a number somebody already has is not usable.
        while (Sale::query()->allBranches()->where('invoice_no', $number)->exists()) {
            $sequence++;
            $number = preg_replace('/\d+$/', str_pad((string) $sequence, 5, '0', STR_PAD_LEFT), $number);
        }

        return $number;
    }

    /** Held sales get their own handle so they do not spend an invoice number. */
    protected function nextHoldReference(): string
    {
        $last = Sale::query()
            ->allBranches()
            ->where('status', SaleStatus::Held)
            ->orderByDesc('id')
            ->value('invoice_no');

        $number = $last !== null && preg_match('/(\d+)$/', $last, $m) ? ((int) $m[1]) + 1 : 1;

        return 'HOLD-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Where the sale happened.
     *
     * The till's own branch, then the seller's, and finally the main branch —
     * the same fallback {@see InventoryService} uses, so an owner who is not
     * parked in a particular shop can still sell.
     */
    protected function resolveBranch(mixed $branchId): int
    {
        $branchId = filled($branchId)
            ? (int) $branchId
            : (int) (Auth::guard('web')->user()?->branch_id ?? 0);

        if ($branchId === 0) {
            $branchId = (int) (Branch::query()->where('is_main', true)->value('id')
                ?? Branch::query()->orderBy('id')->value('id')
                ?? 0);
        }

        abort_if($branchId === 0, 422, 'A sale needs a branch.');
        abort_unless($this->branches->allows($branchId), 403, 'That branch is outside your access.');

        return $branchId;
    }

    /**
     * The session this sale belongs to, if the shop uses them.
     *
     * When `require_cash_session` is on, selling without an open drawer is
     * refused — a shop that counts its till needs every sale inside a session or
     * the cash-up cannot balance.
     */
    protected function resolveSession(int $branchId, ?int $counterId): ?CashSession
    {
        $session = $this->cashSessions->currentFor($branchId, $counterId);

        if ($session === null && (bool) config('pos.require_cash_session', false)) {
            abort(422, 'Open the till before selling — this shop records a cash session for every sale.');
        }

        return $session;
    }

    protected function resolveCustomer(mixed $customerId): ?Customer
    {
        if (blank($customerId)) {
            abort_unless(
                (bool) config('pos.allow_walk_in', true),
                422,
                'This shop records a customer on every sale.',
            );

            return null;
        }

        $customer = Customer::find((int) $customerId);

        abort_if($customer === null, 422, 'That customer does not exist in this business.');

        return $customer;
    }

    protected function resolveVariant(Product $product, mixed $variantId): ?int
    {
        $product->loadMissing('variants');

        if ($product->hasVariants()) {
            abort_if(blank($variantId), 422, "\"{$product->name}\" has variants — say which one.");

            abort_unless(
                $product->variants->contains('id', (int) $variantId),
                422,
                'That variant does not belong to this product.',
            );

            return (int) $variantId;
        }

        return null;
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::SALES_INVOICING)) {
            throw new FeatureUnavailableException(FeatureRegistry::SALES_INVOICING, 'Sales');
        }
    }
}
