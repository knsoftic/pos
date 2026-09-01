<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Is this installation actually ready to face customers? (#115, #191)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A COMMAND AND NOT A CHECKLIST IN THE README
 *
 * Every item here is something a deployment checklist would tell you to do, and
 * every one of them is something people skip — not through carelessness, but
 * because a checklist cannot tell you whether YOUR box is in the right state.
 * "Make sure debug is off" is advice. "APP_DEBUG is on and this is production"
 * is a fact about the machine you are standing on.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FAIL vs WARN, and why the line is where it is
 *
 *   FAIL — someone can get in, or the app cannot work. A demo account with the
 *          password "password" is not a tidiness problem.
 *   WARN — a real risk or a real degradation, but the shop can trade. HTTPS,
 *          caches, mail.
 *
 * Only failures set the exit code, so this can go in a deploy pipeline as-is.
 * `--strict` promotes warnings for anyone who wants the stricter gate.
 *
 * ⚠️ Passing this does NOT mean the installation is secure. It means the
 * mistakes that are cheap to check for have not been made. Nothing here
 * inspects the server, the database grants, or the network.
 */
class Preflight extends Command
{
    protected $signature = 'pos:preflight {--strict : Treat warnings as failures}';

    protected $description = 'Check this installation for the classic deployment mistakes';

    protected const PASS = 'pass';

    protected const WARN = 'warn';

    protected const FAIL = 'fail';

    /** @var list<array{status: string, label: string, detail: string}> */
    protected array $results = [];

    public function handle(): int
    {
        $this->components->info('Preflight — '.config('app.env').' · '.config('app.url'));

        $this->checkAppKey();
        $this->checkDebug();
        $this->checkMigrations();
        $this->checkOperatorExists();
        $this->checkDemoAccounts();
        $this->checkSeededPasswords();
        $this->checkCredentialsFile();
        $this->checkWritablePaths();

        $this->checkHttps();
        $this->checkSecureCookies();
        $this->checkHsts();
        $this->checkMail();
        $this->checkDrivers();
        $this->checkCaches();
        $this->checkStorageLink();
        $this->checkScheduler();
        $this->checkBackups();

        return $this->report();
    }

    // ══════════════════════════════════════════════════════════ the failures

    protected function checkAppKey(): void
    {
        $this->record(
            config('app.key') ? self::PASS : self::FAIL,
            'Application key',
            config('app.key')
                ? 'Set.'
                : 'Missing. Sessions and every encrypted column are broken without it — run `php artisan key:generate`.',
        );
    }

    protected function checkDebug(): void
    {
        if (! app()->isProduction()) {
            $this->record(self::PASS, 'Debug mode', 'Not production; debug is fine here.');

            return;
        }

        $this->record(
            config('app.debug') ? self::FAIL : self::PASS,
            'Debug mode',
            config('app.debug')
                // The 500 page was built to leak nothing. With debug on, Laravel
                // never reaches it and shows the stack trace instead (#93).
                ? 'APP_DEBUG is ON in production. Every error becomes a stack trace, a file path and a database name, shown to whoever caused it.'
                : 'Off, as it must be.',
        );
    }

    protected function checkMigrations(): void
    {
        try {
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()
                ->diff(app('migrator')->getRepository()->getRan())
                ->count();
        } catch (Throwable $e) {
            $this->record(self::FAIL, 'Migrations', 'Could not be read: '.$e->getMessage());

            return;
        }

        $this->record(
            $pending === 0 ? self::PASS : self::FAIL,
            'Migrations',
            $pending === 0 ? 'All applied.' : "{$pending} still pending. Run `php artisan migrate --force`.",
        );
    }

    protected function checkOperatorExists(): void
    {
        $count = Admin::query()->count();

        $this->record(
            $count > 0 ? self::PASS : self::FAIL,
            'Operator account',
            $count > 0
                ? Str::plural('super admin', $count)." on file ({$count})."
                // The production seed path deliberately creates nobody, so this
                // is the expected state of a fresh install rather than a fault.
                : 'None. Nobody can reach /admin — run `php artisan pos:make-admin`.',
        );
    }

    protected function checkDemoAccounts(): void
    {
        $admins = Admin::query()->where('email', 'like', '%@pos.test')->count();
        $users = User::query()->withoutGlobalScopes()->where('email', 'like', '%@demo.test')->count();
        $total = $admins + $users;

        $this->record(
            $total === 0 ? self::PASS : self::FAIL,
            'Demo accounts',
            $total === 0
                ? 'None present.'
                : "{$total} seeded demo ".Str::plural('account', $total).' still exist. Delete them, or re-password every one (#191).',
        );
    }

    protected function checkSeededPasswords(): void
    {
        /*
         | Bcrypt is expensive on purpose, so this cannot walk every account on a
         | large install. It checks the ones that matter most and says plainly
         | that it stopped — a check that quietly examines a sample and reports
         | a clean bill is worse than no check.
         */
        $accounts = Admin::query()->select('email', 'password')->limit(50)->get()
            ->concat(
                User::query()->withoutGlobalScopes()
                    ->where('is_business_owner', true)
                    ->select('email', 'password')->limit(50)->get()
            );

        $weak = $accounts->filter(fn ($account) => Hash::check('password', $account->password));

        $this->record(
            $weak->isEmpty() ? self::PASS : self::FAIL,
            'Seeded passwords',
            $weak->isEmpty()
                ? 'None of the operator or owner accounts checked still use the seeded password.'
                : $weak->count().' account(s) still sign in with "password": '.$weak->pluck('email')->implode(', '),
        );
    }

    protected function checkCredentialsFile(): void
    {
        /*
         | The repo no longer ships any of these — LOGIN_CREDENTIALS.md was
         | deleted before it went public, because default credentials written
         | down in one place are default credentials somebody finds. The check
         | stays for the commoner route onto a server: a developer copying their
         | own working notes up with the deploy.
         */
        $found = collect(['LOGIN_CREDENTIALS.md', 'CREDENTIALS.md', 'credentials.txt', '.env.backup'])
            ->filter(fn (string $file) => File::exists(base_path($file)));

        $this->record(
            $found->isEmpty() ? self::PASS : (app()->isProduction() ? self::FAIL : self::WARN),
            'Credential notes',
            $found->isEmpty()
                ? 'None on this machine.'
                : $found->implode(', ').' — these belong on a developer machine, never on a server.',
        );
    }

    protected function checkWritablePaths(): void
    {
        $unwritable = collect([storage_path(), storage_path('logs'), base_path('bootstrap/cache')])
            ->reject(fn (string $path) => File::isDirectory($path) && File::isWritable($path));

        $this->record(
            $unwritable->isEmpty() ? self::PASS : self::FAIL,
            'Writable paths',
            $unwritable->isEmpty()
                ? 'storage/ and bootstrap/cache are writable.'
                : 'Not writable: '.$unwritable->implode(', '),
        );
    }

    // ══════════════════════════════════════════════════════════ the warnings

    protected function checkHttps(): void
    {
        $url = (string) config('app.url');
        $secure = str_starts_with($url, 'https://');

        $this->record(
            $secure ? self::PASS : self::WARN,
            'APP_URL',
            $secure
                ? $url
                // Not merely eavesdropping: the session cookie travels with
                // every request, so plain HTTP hands over the whole session.
                : $url.' is not https. Every session cookie crosses the network in the clear.',
        );
    }

    protected function checkSecureCookies(): void
    {
        $secure = (bool) config('session.secure');

        $this->record(
            $secure ? self::PASS : self::WARN,
            'Secure session cookie',
            $secure
                ? 'SESSION_SECURE_COOKIE is on.'
                : 'Off. The browser will send the session cookie over plain HTTP too, which undoes HTTPS for anyone who can force one request.',
        );
    }

    protected function checkHsts(): void
    {
        $enabled = (bool) config('security.headers.hsts_enabled');

        $this->record(
            $enabled ? self::PASS : self::WARN,
            'HSTS',
            $enabled
                ? 'Enabled (sent only over HTTPS).'
                : 'Off. Turn it on once HTTPS is settled — it is deliberately not the default, because a max-age is not retractable.',
        );
    }

    protected function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        $usable = ! in_array($mailer, ['log', 'array'], true);

        $this->record(
            $usable ? self::PASS : self::WARN,
            'Mail',
            $usable
                ? "Sending via {$mailer}."
                // The failure is silent, which is what makes it worth a check:
                // the reset screen says "check your email" either way.
                : "MAIL_MAILER is \"{$mailer}\". Password resets will appear to work and no email will arrive.",
        );
    }

    protected function checkDrivers(): void
    {
        $volatile = collect([
            'session' => config('session.driver'),
            'cache' => config('cache.default'),
        ])->filter(fn ($driver) => $driver === 'array');

        $this->record(
            $volatile->isEmpty() ? self::PASS : self::WARN,
            'Drivers',
            $volatile->isEmpty()
                ? 'session='.config('session.driver').', cache='.config('cache.default').', queue='.config('queue.default')
                : $volatile->keys()->implode(', ').' set to "array" — state is thrown away between requests.',
        );
    }

    protected function checkCaches(): void
    {
        $cached = collect([
            'config' => app()->configurationIsCached(),
            'routes' => app()->routesAreCached(),
            'events' => app()->eventsAreCached(),
        ]);

        $missing = $cached->filter(fn (bool $is) => ! $is)->keys();

        if ($missing->isEmpty()) {
            $this->record(self::PASS, 'Caches', 'config, routes and events are cached.');

            return;
        }

        // Uncached is the RIGHT state outside production — a cached config is
        // how a developer spends an afternoon on a .env change that has no
        // effect. So the status and the sentence have to agree about that.
        $this->record(
            app()->isProduction() ? self::WARN : self::PASS,
            'Caches',
            app()->isProduction()
                ? 'Not cached: '.$missing->implode(', ').'. Run `php artisan optimize` as part of the deploy.'
                : 'Not cached ('.$missing->implode(', ').') — correct outside production.',
        );
    }

    protected function checkStorageLink(): void
    {
        $linked = File::exists(public_path('storage'));

        $this->record(
            $linked ? self::PASS : self::WARN,
            'Storage link',
            $linked
                ? 'public/storage exists.'
                : 'Missing. Product images and uploaded receipts will 404 — run `php artisan storage:link`.',
        );
    }

    protected function checkScheduler(): void
    {
        /*
         | The scheduler stamps a heartbeat every five minutes (routes/console.php).
         | Asking the cache when it last did so is the only way this process can
         | know whether cron is actually installed — and "we cannot tell" is a
         | more useful answer than a reassuring guess.
         */
        $last = Cache::get('pos.scheduler.heartbeat');

        if ($last === null) {
            $this->record(self::WARN, 'Scheduler', 'No heartbeat recorded yet. If cron is installed, this clears within five minutes.');

            return;
        }

        // (int): Carbon 3 returns a float here, and "cron last ran
        // 7.0166666666667 minutes ago" is a sentence nobody wants at 3am.
        $minutes = (int) now()->diffInMinutes($last, absolute: true);

        $this->record(
            $minutes <= 15 ? self::PASS : self::WARN,
            'Scheduler',
            $minutes <= 15
                ? "Last ran {$minutes} minute(s) ago."
                : "Last heartbeat was {$minutes} minutes ago — cron looks stopped. Holds, integrity checks and pruning are not running.",
        );
    }

    protected function checkBackups(): void
    {
        $diskName = (string) config('backup.destination.disk');
        $local = config("filesystems.disks.{$diskName}.driver") === 'local';

        try {
            $archives = collect(Storage::disk($diskName)->files(config('backup.destination.path')))
                ->filter(fn (string $file) => str_contains(basename($file), 'pos-backup-'));
        } catch (Throwable $e) {
            $this->record(self::WARN, 'Backups', 'Destination unreadable: '.$e->getMessage());

            return;
        }

        if ($archives->isEmpty()) {
            $this->record(self::WARN, 'Backups', 'None taken yet. Run `php artisan pos:backup` once by hand and restore it before you rely on it.');

            return;
        }

        $newest = $archives->max(fn (string $file) => Storage::disk($diskName)->lastModified($file));
        $hours = (int) now()->diffInHours(now()->setTimestamp($newest), absolute: true);
        $stale = $hours > (int) config('backup.stale_after_hours');

        $this->record(
            $stale ? self::WARN : ($local ? self::WARN : self::PASS),
            'Backups',
            match (true) {
                $stale => "Newest archive is {$hours} hours old. The nightly job is not running.",
                // Never a clean pass while the only copy shares a disk with the
                // database: it survives a mistake and nothing else.
                $local => "{$archives->count()} on the \"{$diskName}\" disk, newest {$hours}h old — but that is the same machine as the database. Set BACKUP_DISK off-box.",
                default => "{$archives->count()} on \"{$diskName}\", newest {$hours}h old.",
            },
        );
    }

    // ═══════════════════════════════════════════════════════════════ plumbing

    protected function record(string $status, string $label, string $detail): void
    {
        $this->results[] = compact('status', 'label', 'detail');
    }

    protected function report(): int
    {
        $this->newLine();

        foreach ($this->results as $result) {
            [$icon, $colour] = match ($result['status']) {
                self::PASS => ['✔', 'info'],
                self::WARN => ['!', 'comment'],
                default => ['✘', 'error'],
            };

            $this->line(sprintf(
                ' <fg=gray>%s</> <%s>%-22s</%s> %s',
                $icon,
                $colour,
                $result['label'],
                $colour,
                $result['detail'],
            ));
        }

        $failed = collect($this->results)->where('status', self::FAIL)->count();
        $warned = collect($this->results)->where('status', self::WARN)->count();

        $this->newLine();

        if ($failed > 0) {
            $this->error("{$failed} blocking ".Str::plural('problem', $failed).", {$warned} ".Str::plural('warning', $warned).'.');

            return self::FAILURE;
        }

        if ($warned > 0 && $this->option('strict')) {
            $this->error("{$warned} ".Str::plural('warning', $warned).', and --strict was asked for.');

            return self::FAILURE;
        }

        $this->info($warned > 0
            ? "Nothing blocking. {$warned} ".Str::plural('warning', $warned).' worth reading.'
            : 'Ready.');

        return self::SUCCESS;
    }
}
