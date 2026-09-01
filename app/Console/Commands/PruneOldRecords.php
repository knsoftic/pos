<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Expense;
use App\Models\OtherIncome;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Housekeeping (#169, #170).
 *
 * ================= WHAT IS SAFE TO PRUNE, AND WHAT IS NOT =================
 * Only the AUDIT TRAIL, and only past a retention window the operator sets.
 *
 * Nothing financial is touched, ever. A sale, a return, a ledger entry, a stock
 * movement — those are corrected by an opposite entry, never deleted (#133,
 * #198), and a "cleanup" job that quietly removed them would destroy exactly
 * the records somebody needs when a figure is questioned.
 *
 * The audit log is different: it records who did what, it grows without bound,
 * and after a few years its value is archival rather than operational. Even so,
 * the default is a long window and the deletion is chunked and reported.
 *
 * ================= ORPHANED FILES =================
 * A receipt image whose expense was deleted, or a logo replaced twice while a
 * write failed. These are cheap to find and free to remove, and leaving them
 * means a disk that only ever grows.
 */
class PruneOldRecords extends Command
{
    protected $signature = 'pos:prune
                            {--days= : Keep audit entries newer than this many days}
                            {--dry-run : Report what would go, and change nothing}';

    protected $description = 'Prune the audit trail past its retention window, and orphaned uploads';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('audit.retention_days', 730));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 30) {
            // A retention window shorter than a month is almost certainly a
            // typo, and this command cannot undo itself.
            $this->error('Refusing to prune below a 30-day window.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $count = AuditLog::query()->where('created_at', '<', $cutoff)->count();

        if ($dryRun) {
            $this->info("{$count} audit ".str('entry')->plural($count)." older than {$days} days would be pruned.");
        } elseif ($count > 0) {
            // Chunked: a single DELETE over a few million rows locks the table
            // for as long as it takes, on a database a shop is trying to sell
            // through.
            $deleted = 0;

            do {
                $batch = AuditLog::query()->where('created_at', '<', $cutoff)->limit(1000)->delete();
                $deleted += $batch;
            } while ($batch > 0);

            $this->info("{$deleted} audit ".str('entry')->plural($deleted).' pruned.');
        } else {
            $this->info('Nothing to prune.');
        }

        $this->pruneOrphanedUploads($dryRun);

        return self::SUCCESS;
    }

    /**
     * Files on disk that nothing references any more.
     *
     * ⚠️ Only under the app's own upload paths, and only files older than a day
     * — a file uploaded a second ago may be mid-transaction, and deleting it
     * would break a write that was about to succeed.
     */
    protected function pruneOrphanedUploads(bool $dryRun): void
    {
        $disk = Storage::disk(config('uploads.products.disk'));
        $base = config('uploads.products.path');

        $referenced = collect()
            ->merge(Product::query()->allTenants()->whereNotNull('image_path')->pluck('image_path'))
            ->merge(Business::query()->withTrashed()->whereNotNull('logo_path')->pluck('logo_path'))
            ->merge(Expense::query()->allTenants()->allBranches()->whereNotNull('attachment_path')->pluck('attachment_path'))
            ->merge(OtherIncome::query()->allTenants()->allBranches()->whereNotNull('attachment_path')->pluck('attachment_path'))
            ->merge(PlatformSetting::query()->where('key', 'brand.logo_path')->pluck('value')->map(fn ($v) => json_decode((string) $v, true)))
            ->merge(Setting::query()->allTenants()->where('key', 'pos.payment_qr_path')->pluck('value')->map(fn ($v) => json_decode((string) $v, true)))
            ->filter()
            ->flip();

        $orphans = 0;

        foreach (array_merge($disk->allFiles($base), $disk->allFiles(config('uploads.receipts.path'))) as $file) {
            if ($referenced->has($file)) {
                continue;
            }

            if ($disk->lastModified($file) > now()->subDay()->getTimestamp()) {
                continue;
            }

            $orphans++;

            if (! $dryRun) {
                $disk->delete($file);
            }
        }

        $this->info($dryRun
            ? "{$orphans} orphaned ".str('file')->plural($orphans).' would be removed.'
            : "{$orphans} orphaned ".str('file')->plural($orphans).' removed.');
    }
}
