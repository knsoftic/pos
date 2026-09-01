<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a REAL installation needs, and nothing else (#112, #114, #191).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RULE THIS FILE EXISTS TO ENFORCE
 *
 * `php artisan migrate --seed` is the most natural thing to type after a
 * deploy. Until now it also created a super admin at `superadmin@pos.test`
 * whose password was the word "password", on a server facing the internet, and
 * said nothing about it.
 *
 * So the split is structural rather than advisory: everything below is safe to
 * run on a production box on day one and again on day four hundred, and the
 * demo shop lives in {@see DemoSeeder}, which refuses to run in production.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT COUNTS AS "NEEDED"
 *
 * Only the two registries and a starting plan catalogue:
 *
 *   Features and limits are the VOCABULARY the code checks against. They are
 *   not operator data — a feature exists because some code path asks about it —
 *   so a fresh database without them is not empty, it is broken.
 *
 *   Plans ARE operator data, and these rows are only a sensible starting point
 *   so the first login is not staring at an empty catalogue (#190). Renaming,
 *   repricing or deleting them is expected.
 *
 * No admin account is created here. Creating one would mean choosing its
 * password, and every way of doing that automatically ends with a password
 * somebody else can also guess. `php artisan pos:preflight` will tell you that
 * you have no operator yet, and there is a documented command to make one.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FeatureSeeder::class,
            LimitSeeder::class,
            PlanSeeder::class,
        ]);

        /*
         | The demo shop, outside production only. DemoSeeder checks the
         | environment for itself as well — this is the convenience, that is
         | the control, and a control you can reach by another route is not one.
         */
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        } else {
            $this->command?->info('Production: demo data skipped. Run `php artisan pos:preflight` next.');
        }
    }
}
