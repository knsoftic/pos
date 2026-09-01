<?php

namespace Tests\Feature\Deployment;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Backups (#95).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. THE UPLOADS GO WITH THE DUMP. A database restore without them leaves a
 *     catalogue of broken images and an expense trail whose receipts are gone —
 *     and a pointer with nothing behind it looks like data.
 *  2. A TRUNCATED DUMP IS NOT FILED AS A BACKUP. mysqldump can exit zero having
 *     written a header and nothing else, and that file sits in the archive
 *     looking exactly like the real thing until somebody needs it.
 *  3. THE WARNING IS NEVER SUPPRESSED. A local archive survives a mistake and
 *     nothing else, and a green tick is how people come to believe otherwise.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Its own destination per test, so retention can be exercised without
        // deleting anything a developer actually wanted.
        config([
            'filesystems.disks.backup_test' => [
                'driver' => 'local',
                'root' => storage_path('app/backup-test-'.uniqid()),
            ],
            'backup.destination.disk' => 'backup_test',
            'backup.destination.path' => 'backups',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('filesystems.disks.backup_test.root'));

        parent::tearDown();
    }

    protected function disk()
    {
        return Storage::disk('backup_test');
    }

    protected function archives(): array
    {
        return array_values(array_filter(
            $this->disk()->files('backups'),
            fn (string $file) => str_ends_with($file, '.zip'),
        ));
    }

    public function test_a_backup_contains_the_database_and_the_uploaded_files(): void
    {
        $uploads = storage_path('app/backup-source-'.uniqid());
        File::ensureDirectoryExists($uploads.'/products');
        File::put($uploads.'/products/photo.jpg', 'not really a jpeg');

        config(['backup.include' => [$uploads]]);

        try {
            $this->artisan('pos:backup')->assertSuccessful();

            $archives = $this->archives();
            $this->assertCount(1, $archives);

            $zip = new ZipArchive;
            $zip->open($this->disk()->path($archives[0]));

            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[] = $zip->getNameIndex($i);
            }
            $zip->close();

            $this->assertContains('database.sql', $entries);

            // ⚠️ `products.image_path` and `expenses.attachment_path` are
            // pointers. Restoring the rows without the files behind them
            // produces a shop that looks intact and is not.
            $this->assertTrue(
                (bool) collect($entries)->first(fn (string $e) => str_contains($e, 'photo.jpg')),
                'The uploaded files have to travel with the dump.',
            );
        } finally {
            File::deleteDirectory($uploads);
        }
    }

    public function test_the_dump_is_a_real_dump_and_not_an_empty_file(): void
    {
        $this->artisan('pos:backup --database-only')->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($this->disk()->path($this->archives()[0]));
        $sql = $zip->getFromName('database.sql');
        $zip->close();

        // Tables this system cannot be restored without.
        foreach (['sales', 'stock_movements', 'ledger_entries', 'businesses'] as $table) {
            $this->assertStringContainsString("`{$table}`", $sql, "The dump is missing {$table}.");
        }

        // The completion marker is what tells a truncated dump from a good one.
        $this->assertStringContainsString('Dump completed', $sql);
    }

    public function test_database_only_leaves_the_files_out(): void
    {
        $uploads = storage_path('app/backup-source-'.uniqid());
        File::ensureDirectoryExists($uploads);
        File::put($uploads.'/receipt.pdf', 'pdf-ish');

        config(['backup.include' => [$uploads]]);

        try {
            $this->artisan('pos:backup --database-only')->assertSuccessful();

            $zip = new ZipArchive;
            $zip->open($this->disk()->path($this->archives()[0]));
            $count = $zip->numFiles;
            $zip->close();

            $this->assertSame(1, $count, 'Only the dump.');
        } finally {
            File::deleteDirectory($uploads);
        }
    }

    public function test_a_local_destination_always_says_it_is_not_really_a_backup(): void
    {
        // Said every single time, deliberately: an archive beside the database
        // survives a bad migration and not a dead disk, a fire or ransomware.
        $this->artisan('pos:backup --database-only')
            ->expectsOutputToContain('same machine as the database')
            ->assertSuccessful();
    }

    public function test_old_archives_are_pruned_and_nothing_else_is_touched(): void
    {
        $this->artisan('pos:backup --database-only')->assertSuccessful();

        // Something the operator put in the same folder. A backup directory
        // somebody also uses for something else is not a reason to delete
        // their files.
        $this->disk()->put('backups/manual-export.zip', 'not ours');
        $this->disk()->put('backups/pos-backup-2020-01-01-000000.zip', 'ancient');
        touch($this->disk()->path('backups/pos-backup-2020-01-01-000000.zip'), now()->subDays(400)->getTimestamp());

        $this->artisan('pos:backup --database-only --keep=30')->assertSuccessful();

        $files = $this->disk()->files('backups');

        $this->assertContains('backups/manual-export.zip', $files);
        $this->assertNotContains('backups/pos-backup-2020-01-01-000000.zip', $files);
    }

    public function test_a_missing_mysqldump_fails_loudly_and_names_the_path_it_tried(): void
    {
        config(['backup.mysqldump' => 'C:\\nowhere\\mysqldump.exe']);

        // The commonest real failure, because mysqldump is frequently not on
        // PATH. "Backup failed" alone sends somebody guessing.
        $this->artisan('pos:backup')
            ->expectsOutputToContain('nowhere')
            ->assertFailed();

        $this->assertSame([], $this->archives(), 'Nothing may be filed when the dump did not happen.');
    }

    public function test_the_backup_runs_before_the_integrity_sweep(): void
    {
        $times = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [$event->command => $event->expression]);

        $backup = collect($times)->keys()->first(fn ($c) => str_contains((string) $c, 'pos:backup'));
        $check = collect($times)->keys()->first(fn ($c) => str_contains((string) $c, 'pos:check-integrity'));

        $this->assertNotNull($backup);
        $this->assertNotNull($check);

        // ⚠️ Order matters. If the sweep finds drift, the most recent archive
        // has to be from BEFORE whatever a repair does to it — a backup taken
        // afterwards has already lost the state worth looking at.
        $hour = fn (string $expression) => (int) explode(' ', $expression)[1];

        $this->assertLessThan($hour($times[$check]), $hour($times[$backup]));
    }
}
