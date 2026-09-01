<?php

namespace Tests\Feature\Deployment;

use App\Models\Admin;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Deployment preparation (#112, #114, #115, #191).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. `migrate --seed` ON A PRODUCTION SERVER MUST NOT CREATE A LOGIN. It used
 *     to create a super admin whose password was the word "password", said
 *     nothing about it, and the only warning was a comment in the file.
 *  2. THE CHECK HAS TO FAIL WHEN IT SHOULD. A preflight that passes on a badly
 *     configured box is worse than none, because it is trusted.
 *  3. THE TWO ENV FILES CANNOT DRIFT. The failure mode of a second .env example
 *     is that a setting is added to one and the other is quietly a year stale.
 */
class PreflightTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed the way a server would: as production, and `--force` past Laravel's
     * "do you really wish to run this?" prompt — which is the very command a
     * deploy script types, so it is the path worth testing.
     */
    protected function seedAsProduction(string $class): void
    {
        app()['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--class' => $class, '--force' => true])->run();
        } finally {
            app()['env'] = 'testing';
        }
    }

    // ============================================ the seeder split (#191)

    public function test_the_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->seedAsProduction(DemoSeeder::class);

        // ⚠️ THE WHOLE POINT. `php artisan migrate --seed` is the most natural
        // thing to type after a deploy, and it used to plant
        // superadmin@pos.test / "password" on a box facing the internet.
        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(0, User::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Business::query()->withoutGlobalScopes()->count());
    }

    public function test_a_staging_box_can_still_ask_for_demo_data(): void
    {
        // A staging server honestly reports itself as production. Refusing it
        // outright would mean lying about the environment to get a demo — which
        // is how the guard would end up switched off everywhere.
        putenv('ALLOW_DEMO_SEED=true');

        try {
            $this->seedAsProduction(DemoSeeder::class);
        } finally {
            putenv('ALLOW_DEMO_SEED');
        }

        $this->assertGreaterThan(0, Admin::query()->count());
    }

    public function test_the_production_seed_path_plants_the_vocabulary_and_no_accounts(): void
    {
        $this->seedAsProduction(DatabaseSeeder::class);

        // Features and limits are the vocabulary the code checks against — a
        // database without them is not empty, it is broken.
        $this->assertGreaterThan(0, Feature::query()->count());
        $this->assertGreaterThan(0, Limit::query()->count());
        $this->assertGreaterThan(0, Plan::query()->count());

        // …and nobody can sign in, which is the correct state of a fresh
        // install. `pos:make-admin` is how a key gets cut.
        $this->assertSame(0, Admin::query()->count());
    }

    public function test_an_operator_can_be_created_from_the_console(): void
    {
        $this->artisan('pos:make-admin', ['--name' => 'Operator', '--email' => 'ops@example.test'])
            ->expectsQuestion('Password', 'Str0ng-Enough-Pass')
            ->expectsQuestion('Confirm password', 'Str0ng-Enough-Pass')
            ->assertSuccessful();

        $this->assertDatabaseHas('admins', ['email' => 'ops@example.test']);
    }

    public function test_the_console_applies_the_same_password_policy_as_the_login_screen(): void
    {
        // An operator account is the one that should least be allowed a weak
        // password, and a CLI shortcut past the rules would make it the one
        // most likely to have one.
        $this->artisan('pos:make-admin', ['--name' => 'Operator', '--email' => 'weak@example.test'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('admins', ['email' => 'weak@example.test']);
    }

    // ================================================== the preflight (#115)

    public function test_preflight_fails_on_a_box_that_still_has_the_demo_logins(): void
    {
        $this->seed(DemoSeeder::class);

        $this->artisan('pos:preflight')
            ->expectsOutputToContain('seeded demo')
            ->assertFailed();
    }

    public function test_preflight_names_the_accounts_still_using_the_seeded_password(): void
    {
        $this->seed(DemoSeeder::class);

        // Naming them matters: "some accounts are weak" sends somebody hunting
        // through a user table, and hunting is what does not get done.
        $this->artisan('pos:preflight')
            ->expectsOutputToContain('superadmin@pos.test')
            ->assertFailed();
    }

    public function test_preflight_passes_on_a_clean_install_with_an_operator(): void
    {
        // As production, so the demo shop is NOT planted — outside production
        // `DatabaseSeeder` deliberately includes it, and preflight is right to
        // fail on that.
        $this->seedAsProduction(DatabaseSeeder::class);

        Admin::factory()->create([
            'email' => 'real.operator@example.com',
            'password' => 'a-password-nobody-seeded',
        ]);

        // Warnings are expected here — this is a dev box over http with no
        // mail. Only failures set the exit code, so it can sit in a pipeline.
        $this->artisan('pos:preflight')->assertSuccessful();
    }

    public function test_strict_turns_the_warnings_into_a_failure(): void
    {
        $this->seedAsProduction(DatabaseSeeder::class);

        Admin::factory()->create([
            'email' => 'real.operator@example.com',
            'password' => 'a-password-nobody-seeded',
        ]);

        $this->artisan('pos:preflight --strict')->assertFailed();
    }

    public function test_preflight_reports_a_stopped_scheduler(): void
    {
        Cache::put('pos.scheduler.heartbeat', now()->subHours(6));

        // A cron entry that was never installed looks exactly like a quiet
        // week. Without the heartbeat the honest answer is "we cannot tell".
        $this->artisan('pos:preflight')->expectsOutputToContain('cron looks stopped');
    }

    public function test_preflight_notices_a_missing_operator(): void
    {
        $this->artisan('pos:preflight')
            ->expectsOutputToContain('pos:make-admin')
            ->assertFailed();
    }

    // ================================================ the env files (#115)

    public function test_the_two_env_examples_carry_exactly_the_same_keys(): void
    {
        $keys = function (string $path): array {
            preg_match_all('/^([A-Z0-9_]+)=/m', File::get(base_path($path)), $matches);
            $found = $matches[1];
            sort($found);

            return array_values(array_unique($found));
        };

        // ⚠️ The failure mode of a second env example is not that it is wrong —
        // it is that somebody adds a setting to one file and the other is
        // quietly a year out of date, and nobody finds out until the setting
        // they needed was never on the server.
        $this->assertSame(
            $keys('.env.example'),
            $keys('.env.production.example'),
            'The two .env examples have drifted. Add the key to both.',
        );
    }

    public function test_the_production_example_is_actually_production_shaped(): void
    {
        $production = File::get(base_path('.env.production.example'));

        $this->assertStringContainsString('APP_ENV=production', $production);
        $this->assertStringContainsString('APP_DEBUG=false', $production);
        $this->assertStringContainsString('SECURITY_HSTS_ENABLED=true', $production);
        $this->assertStringContainsString('ALLOW_DEMO_SEED=false', $production);

        // Empty on purpose. A plausible placeholder in a committed file is a
        // credential somebody eventually ships.
        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $production);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $production);
    }

    public function test_every_env_key_the_app_reads_from_its_own_config_is_documented(): void
    {
        $example = File::get(base_path('.env.example'));

        /*
         | ⚠️ DERIVED, NOT LISTED. This test used to carry a hand-written list of
         | our config files — and `config/backup.php` was added later, never got
         | added to the list, and its five keys went undocumented in BOTH env
         | examples while this test sat there passing. A guard with a list you
         | have to remember to update is the thing it was guarding against.
         |
         | So: everything in config/ EXCEPT the framework's own files, which
         | carry dozens of driver knobs nobody sets.
         */
        $framework = ['app', 'auth', 'cache', 'database', 'filesystems', 'logging', 'mail', 'queue', 'services', 'session'];

        $ours = collect(File::files(config_path()))
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->reject(fn (string $name) => in_array($name, $framework, true))
            ->values();

        $this->assertContains('backup', $ours->all(), 'The derivation must pick up new config files on its own.');

        $missing = [];

        foreach ($ours as $file) {
            preg_match_all("/env\('([A-Z0-9_]+)'/", File::get(config_path("{$file}.php")), $matches);

            foreach ($matches[1] as $key) {
                if (! preg_match("/^{$key}=/m", $example)) {
                    $missing[] = "{$file}.php → {$key}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            'A setting nobody can find is a setting nobody uses.');
    }
}
