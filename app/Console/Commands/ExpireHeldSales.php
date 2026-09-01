<?php

namespace App\Console\Commands;

use App\Enums\SaleStatus;
use App\Models\Business;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Discard held sales nobody came back for (#169, #170).
 *
 * ================= WHY THIS IS SAFE TO AUTOMATE =================
 * A held sale has posted NOTHING — no stock moved, no money recorded, no
 * invoice number spent (#118). Discarding one throws away a basket, not a
 * transaction, which is why this is the only destructive thing in the whole
 * schedule.
 *
 * The window is the shop's own setting (`pos.hold_expiry_hours`), so a shop
 * that parks orders for three days can say so.
 *
 * ⚠️ Runs PER TENANT inside `TenantContext::runFor`, because the window is a
 * per-shop setting and the global scopes have to be pointed at somebody. A
 * cross-tenant sweep with one window would apply one shop's policy to all of
 * them.
 */
class ExpireHeldSales extends Command
{
    protected $signature = 'pos:expire-holds {--dry-run : List what would go, and change nothing}';

    protected $description = 'Discard held sales past the shop\'s hold window';

    public function handle(TenantContext $tenant, SaleService $sales): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $discarded = 0;

        Business::query()->cursor()->each(function ($business) use ($tenant, $sales, $dryRun, &$discarded) {
            $tenant->runFor($business, function () use ($sales, $dryRun, &$discarded, $business) {
                // Read AFTER runFor, so it is this shop's window (the settings
                // overlay is applied by runFor).
                $hours = (int) config('pos.hold_expiry_hours', 24);

                if ($hours <= 0) {
                    return;
                }

                $stale = Sale::query()
                    ->allBranches()
                    ->where('status', SaleStatus::Held)
                    ->where('created_at', '<', now()->subHours($hours))
                    ->get();

                foreach ($stale as $sale) {
                    if ($dryRun) {
                        $this->line("  would discard {$sale->id} ({$business->name})");
                        $discarded++;

                        continue;
                    }

                    try {
                        $sales->discardHold($sale);
                        $discarded++;
                    } catch (\Throwable $e) {
                        // One bad row must not stop the sweep for everybody
                        // else — the whole point of a nightly job is that it
                        // finishes.
                        Log::warning('Could not discard a held sale.', [
                            'sale_id' => $sale->id,
                            'business_id' => $business->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        });

        $this->info($dryRun
            ? "{$discarded} held ".str('sale')->plural($discarded).' would be discarded.'
            : "{$discarded} held ".str('sale')->plural($discarded).' discarded.');

        return self::SUCCESS;
    }
}
