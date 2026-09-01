<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Create a super admin — the SaaS operator's own login (#112, #191).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS HAD TO EXIST BEFORE THE SEEDER COULD BE MADE SAFE
 *
 * The only operator account this application had ever created was the seeded
 * `superadmin@pos.test`, password "password". Taking that out of the production
 * seed path — which was the right thing to do — left a real installation with
 * no operator and no way to make one. The lock was fixed; somebody still has to
 * be given a key.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT ASKS FOR THE PASSWORD; IT NEVER TAKES ONE AS AN ARGUMENT
 *
 * There is deliberately no `--password=` option. A password on a command line
 * is written to the shell history file, is visible in `ps` to every other user
 * on the box while the command runs, and ends up pasted into the deployment
 * notes that get shared. `secret()` reads it without echoing and it goes
 * nowhere else.
 *
 * The same policy the login screen enforces applies here (`Password::defaults`,
 * config/security.php) — an operator account is the one that should least be
 * allowed a weak password, and a CLI shortcut that skipped the rules would make
 * it the one most likely to have one.
 */
class MakeAdmin extends Command
{
    protected $signature = 'pos:make-admin
                            {--name= : The operator\'s name}
                            {--email= : Their sign-in address}';

    protected $description = 'Create a super admin for the /admin panel';

    public function handle(AuditService $audit): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');

        $password = $this->secret('Password');
        $confirm = $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirm,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:admins,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = Admin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        // Who was handed the keys, and when, is exactly the sort of thing an
        // audit trail is for — and the one event nobody can reconstruct later.
        $audit->log('admin.created', $admin, "Super admin {$email} created from the console.", actor: $admin);

        $this->newLine();
        $this->info("Super admin created: {$email}");
        $this->line('  Sign in at '.route('admin.login'));

        return self::SUCCESS;
    }
}
