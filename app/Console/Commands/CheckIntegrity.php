<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\CustomerLedgerService;
use App\Services\InventoryService;
use App\Services\SupplierLedgerService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly proof that the cached figures still match their ledgers (#169, #170).
 *
 * ================= WHY THIS EXISTS =================
 * This whole system runs on one pattern: the LEDGER is the truth and the column
 * is a cache of it, written in the same locked transaction. `recalculate()` is
 * both the repair tool and the proof — if it ever changes a number, the cache
 * had drifted.
 *
 * That pattern is only worth anything if somebody actually checks. A silent
 * drift is exactly the bug that gets discovered months later during a stock
 * count, when nobody can say when it started. This runs every night and says.
 *
 * ================= IT REPORTS BEFORE IT REPAIRS =================
 * By default it only LOOKS. Quietly correcting a discrepancy would destroy the
 * evidence of whatever caused it, and the cause matters more than the symptom.
 * `--repair` is there for when somebody has decided.
 */
class CheckIntegrity extends Command
{
    protected $signature = 'pos:check-integrity
                            {--repair : Rebuild the drifted caches from their ledgers}
                            {--business= : Only this business id}';

    protected $description = 'Check every cached balance against the ledger it is a cache of';

    public function handle(
        TenantContext $tenant,
        InventoryService $inventory,
        CustomerLedgerService $customers,
        SupplierLedgerService $suppliers,
    ): int {
        $repair = (bool) $this->option('repair');
        $drifted = 0;

        $businesses = Business::query()
            ->when($this->option('business'), fn ($q) => $q->whereKey((int) $this->option('business')))
            ->cursor();

        foreach ($businesses as $business) {
            $tenant->runFor($business, function () use ($business, $inventory, $customers, $suppliers, $repair, &$drifted) {
                // ---- stock: every shelf against its movements --------------
                foreach (Stock::query()->allBranches()->cursor() as $shelf) {
                    $result = $repair
                        ? $inventory->recalculate($shelf->branch_id, $shelf->product_id, $shelf->product_variant_id)
                        : $this->peekStock($inventory, $shelf);

                    if ($result['drifted']) {
                        $drifted++;
                        $this->report('stock', $business, [
                            'branch_id' => $shelf->branch_id,
                            'product_id' => $shelf->product_id,
                            'cached' => $result['before'],
                            'ledger' => $result['after'],
                        ], $repair);
                    }
                }

                // ---- what people owe, against their entries ----------------
                foreach (Customer::query()->cursor() as $customer) {
                    $this->checkParty('customer', $business, $customer, fn () => $customers->recalculate($customer), $repair, $drifted);
                }

                foreach (Supplier::query()->cursor() as $supplier) {
                    $this->checkParty('supplier', $business, $supplier, fn () => $suppliers->recalculate($supplier), $repair, $drifted);
                }
            });
        }

        if ($drifted === 0) {
            $this->info('Everything reconciles.');

            return self::SUCCESS;
        }

        $this->warn("{$drifted} ".str('figure')->plural($drifted).' did not reconcile.');

        // A non-zero exit so a cron that reports failures actually reports this
        // one — an integrity problem nobody is told about is the same as not
        // checking.
        return $repair ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Look without touching.
     *
     * @return array{before: float, after: float, drifted: bool}
     */
    protected function peekStock(InventoryService $inventory, Stock $shelf): array
    {
        // Summed straight from the movement ledger — the same figure
        // InventoryService::recalculate() would write, without writing it.
        $ledger = round((float) StockMovement::query()
            ->allBranches()
            ->where('branch_id', $shelf->branch_id)
            ->where('product_id', $shelf->product_id)
            ->where(fn ($q) => $shelf->product_variant_id === null
                ? $q->whereNull('product_variant_id')
                : $q->where('product_variant_id', $shelf->product_variant_id))
            ->sum('quantity'), 4);

        $cached = round((float) $shelf->quantity, 4);

        return [
            'before' => $cached,
            'after' => $ledger,
            'drifted' => abs($cached - $ledger) > 0.00005,
        ];
    }

    protected function checkParty(string $kind, Business $business, $party, callable $recalculate, bool $repair, int &$drifted): void
    {
        $cached = round((float) $party->balance, 2);
        $ledger = round((float) $party->ledgerEntries()->latest('id')->value('balance_after') ?? 0, 2);

        if (abs($cached - $ledger) <= 0.005) {
            return;
        }

        $drifted++;

        if ($repair) {
            $recalculate();
        }

        $this->report($kind, $business, [
            'id' => $party->id,
            'name' => $party->name,
            'cached' => $cached,
            'ledger' => $ledger,
        ], $repair);
    }

    /** @param array<string, mixed> $context */
    protected function report(string $kind, Business $business, array $context, bool $repaired): void
    {
        $this->warn(sprintf('  %s drift in %s: %s', $kind, $business->name, json_encode($context)));

        // Logged as a warning, not an error: the figure is wrong but nothing is
        // on fire, and an error-level line would page somebody at 3am for a
        // rounding difference.
        Log::warning('Cached figure drifted from its ledger.', [
            'kind' => $kind,
            'business_id' => $business->id,
            'repaired' => $repaired,
        ] + $context);
    }
}
