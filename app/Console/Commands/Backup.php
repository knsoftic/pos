<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Take a backup: the database, and the files the database points at (#95).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE UPLOADS COME TOO
 *
 * A dump on its own restores a catalogue full of broken images and an expense
 * trail whose receipts have gone. `products.image_path` and
 * `expenses.attachment_path` are pointers, and a pointer with nothing on the
 * other end is worse than a null — it looks like data.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ WHAT THIS COMMAND CANNOT DO, AND SAYS SO
 *
 * Writing to the default `local` disk puts the archive on the same machine as
 * the database. That survives a mistake and nothing else — not a dead disk, not
 * a terminated instance, not ransomware, which encrypts the backups directory
 * with everything else. The command prints that warning every time it uses a
 * local destination, because a reassuring green tick is exactly how people come
 * to believe they are covered.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PASSWORD IS NOT ON THE COMMAND LINE
 *
 * `mysqldump -pSECRET` is visible in `ps` to every other user on the box for as
 * long as the dump runs, which on a real database is minutes. The credentials
 * go into a temporary defaults-file that is deleted in a `finally`, the same
 * reasoning as `pos:make-admin` refusing a --password option.
 */
class Backup extends Command
{
    protected $signature = 'pos:backup
                            {--database-only : Skip the uploaded files}
                            {--keep= : Override the retention window, in days}';

    protected $description = 'Back up the database and uploaded files';

    public function handle(): int
    {
        $stamp = now()->format('Y-m-d-His');
        $work = storage_path('app/backup-tmp-'.$stamp);

        File::ensureDirectoryExists($work);

        try {
            $sql = $this->dumpDatabase($work, $stamp);

            if ($sql === null) {
                return self::FAILURE;
            }

            $archive = $this->buildArchive($work, $stamp, $sql);
            $stored = $this->store($archive, $stamp);
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }

        $this->prune();
        $this->warnAboutLocalDestinations();

        $this->info("Backup written: {$stored}");

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════ the database

    protected function dumpDatabase(string $work, string $stamp): ?string
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        if (($db['driver'] ?? null) !== 'mysql') {
            $this->error("Only MySQL is supported here; this connection is \"{$db['driver']}\".");

            return null;
        }

        $binary = (string) config('backup.mysqldump');

        // A defaults-file, not -p on the command line: an argument is visible in
        // `ps` to every other user on the box for as long as the dump runs.
        $credentials = $work.DIRECTORY_SEPARATOR.'my.cnf';
        File::put($credentials, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n",
            $db['host'] ?? '127.0.0.1',
            $db['port'] ?? 3306,
            $db['username'] ?? '',
            $db['password'] ?? '',
        ));

        $target = $work.DIRECTORY_SEPARATOR."database-{$stamp}.sql";

        $process = new Process([
            $binary,
            '--defaults-extra-file='.$credentials,
            '--single-transaction',   // a consistent snapshot without locking the till out
            '--quick',                // stream rows rather than buffering a large table
            '--routines',
            '--events',
            '--default-character-set=utf8mb4',
            '--result-file='.$target,
            $db['database'],
        ], timeout: 3600);

        $process->run();

        File::delete($credentials);

        if (! $process->isSuccessful()) {
            $this->error("mysqldump failed (looked for it at: {$binary}).");
            $this->line('  '.trim($process->getErrorOutput() ?: 'No output. Check BACKUP_MYSQLDUMP in .env.'));

            return null;
        }

        /*
         | A dump that produced a file is not a dump that produced a BACKUP.
         | mysqldump can exit zero having written a header and nothing else, and
         | that file will sit in the archive looking exactly like the real thing
         | until somebody tries to restore it.
         */
        $size = File::size($target);
        $tail = strtolower((string) File::get($target));

        if ($size < 1024 || ! str_contains($tail, 'dump completed')) {
            $this->error('The dump looks truncated — it has no completion marker. Refusing to file it as a backup.');

            return null;
        }

        $this->line('  Database dumped ('.$this->humanise($size).').');

        return $target;
    }

    // ════════════════════════════════════════════════════════════ the archive

    protected function buildArchive(string $work, string $stamp, string $sql): string
    {
        $path = $work.DIRECTORY_SEPARATOR."pos-backup-{$stamp}.zip";

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the archive at '.$path);
        }

        $zip->addFile($sql, 'database.sql');

        if (! $this->option('database-only')) {
            $files = 0;

            foreach ((array) config('backup.include', []) as $directory) {
                if (! File::isDirectory($directory)) {
                    continue;
                }

                foreach (File::allFiles($directory) as $file) {
                    $zip->addFile(
                        $file->getRealPath(),
                        'files/'.basename($directory).'/'.str_replace('\\', '/', $file->getRelativePathname()),
                    );
                    $files++;
                }
            }

            $this->line("  {$files} uploaded ".str('file')->plural($files).' included.');
        }

        $zip->close();

        return $path;
    }

    protected function store(string $archive, string $stamp): string
    {
        $disk = Storage::disk(config('backup.destination.disk'));
        $name = rtrim((string) config('backup.destination.path'), '/')."/pos-backup-{$stamp}.zip";

        $stream = fopen($archive, 'rb');

        try {
            $disk->put($name, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $name;
    }

    // ═══════════════════════════════════════════════════════════════ tidy up

    protected function prune(): void
    {
        $days = (int) ($this->option('keep') ?? config('backup.retention_days'));

        if ($days < 1) {
            return;
        }

        $disk = Storage::disk(config('backup.destination.disk'));
        $path = rtrim((string) config('backup.destination.path'), '/');
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach ($disk->files($path) as $file) {
            // Only ever our own archives. A backup directory somebody also uses
            // for something else is not a reason to delete their files.
            if (! str_ends_with($file, '.zip') || ! str_contains(basename($file), 'pos-backup-')) {
                continue;
            }

            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->line("  {$removed} archive(s) older than {$days} days removed.");
        }
    }

    protected function warnAboutLocalDestinations(): void
    {
        $disk = (string) config('backup.destination.disk');

        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return;
        }

        // Said every single time, deliberately. A green tick is how people come
        // to believe they are covered.
        $this->warn('  ⚠ This archive is on the same machine as the database.');
        $this->line('    It survives a mistake, not a dead disk, a fire or ransomware.');
        $this->line('    Set BACKUP_DISK to something off-box, or copy it off.');
    }

    protected function humanise(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
