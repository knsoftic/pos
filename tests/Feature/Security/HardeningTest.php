<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\SecurityLogger;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * The walls: headers, error pages, logging and throttles (#93, #94, #100).
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. A DEFENCE THAT COVERS MOST RESPONSES COVERS NOTHING. Headers are asserted
 *     on the app, the admin panel, the public site AND an error page, because
 *     an attacker picks whichever one was forgotten.
 *  2. AN ERROR PAGE NEVER TEACHES. In production a shopkeeper sees no class
 *     name, no file path, no SQL — those describe our stack to someone who was
 *     asking, and help the reader with nothing.
 *  3. WHAT IS LOGGED IS WHAT CANNOT BE RECOVERED OTHERWISE. A rolled-back
 *     transaction leaves no row anywhere; if it is not in a file it never
 *     happened. And nothing secret is ever in that file.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected string $securityLog;

    protected string $financialLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Walled Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);

        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 500]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);
        $this->owner->refresh();

        // Real files, read back at the end of the test: this exercises the
        // channel, the formatter and the redaction together, which a mocked
        // logger would quietly skip.
        $dir = storage_path('logs/testing');
        File::ensureDirectoryExists($dir);

        $this->securityLog = $dir.'/security-'.uniqid().'.log';
        $this->financialLog = $dir.'/financial-'.uniqid().'.log';

        config([
            'logging.channels.security' => ['driver' => 'single', 'path' => $this->securityLog],
            'logging.channels.financial' => ['driver' => 'single', 'path' => $this->financialLog],
        ]);
    }

    protected function tearDown(): void
    {
        File::delete([$this->securityLog, $this->financialLog]);

        parent::tearDown();
    }

    protected function readLog(string $path): string
    {
        return File::exists($path) ? File::get($path) : '';
    }

    // ==================================================== headers (#100)

    public function test_the_security_headers_reach_every_kind_of_page(): void
    {
        $admin = Admin::factory()->create();

        $responses = [
            'public' => $this->get('/'),
            'login' => $this->get(route('login')),
            'app' => $this->actingAs($this->owner)->get(route('app.dashboard')),
            'admin' => $this->actingAs($admin, 'admin')->get(route('admin.dashboard')),
            // The one most likely to be forgotten, and the one an attacker
            // reaches most easily.
            'error' => $this->get('/definitely-not-a-page'),
        ];

        foreach ($responses as $where => $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertNotNull($response->headers->get('Permissions-Policy'), "Permissions-Policy missing on {$where}.");
        }
    }

    public function test_a_signed_in_shop_is_never_cached_by_a_shared_proxy(): void
    {
        // A proxy holding one cashier's sales list and handing it to the next
        // request is a tenant leak that never touches our code.
        $this->actingAs($this->owner)
            ->get(route('app.dashboard'))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // The marketing site is the opposite: it exists to be cached and found.
        $this->assertStringNotContainsString('no-store', (string) $this->get('/')->headers->get('Cache-Control'));
    }

    public function test_hsts_is_never_sent_over_plain_http(): void
    {
        config(['security.headers.hsts_enabled' => true]);

        // A max-age seen once pins that hostname to HTTPS in that browser for a
        // year — including `localhost`, which is how a developer bricks their
        // own machine. Over http it would be ignored anyway, so not sending it
        // is the only correct behaviour, not merely the cautious one.
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'));
    }

    // ================================================ error pages (#93)

    public function test_a_missing_page_explains_itself_without_a_stack_trace(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        // Rendered through {{ $heading }}, so the apostrophe arrives escaped —
        // assertSee escapes the needle the same way by default.
        $response->assertSee("There's nothing here");
        $response->assertDontSee('Symfony');
        $response->assertDontSee('vendor/laravel');
    }

    public function test_a_server_error_leaks_nothing_and_hands_over_a_reference(): void
    {
        config(['app.debug' => false]);

        Route::middleware('web')->get('/__boom', function (): never {
            throw new RuntimeException('SQLSTATE[42S02] table pos_secret_table is missing at /var/www/app.php');
        });

        $response = $this->get('/__boom');

        $response->assertStatus(500);
        $response->assertSee('Something went wrong at our end');

        // ⚠️ THE POINT OF THIS TEST. Each of these tells someone who was asking
        // which framework, which database and which layout they are up against.
        $response->assertDontSee('SQLSTATE');
        $response->assertDontSee('pos_secret_table');
        $response->assertDontSee('/var/www/app.php');
        $response->assertDontSee('RuntimeException');

        // …and what it shows INSTEAD is a code that finds the real trace, so the
        // shopkeeper never has to describe a 500 down a phone line.
        $reference = app(SecurityLogger::class)->reference();
        $response->assertSee($reference);
        $this->assertStringContainsString($reference, $this->readLog($this->securityLog));
    }

    public function test_the_expired_page_says_the_work_is_probably_still_there(): void
    {
        // Almost never an attack: a form left open past the session lifetime.
        // The framework default says "Page Expired" and leaves the person
        // guessing whether they lost their work.
        $this->get('/errors-419-preview'); // no such route; render the view directly

        $html = view('errors.419', ['exception' => null])->render();

        $this->assertStringContainsString('session expired', $html);
        $this->assertStringContainsString('should still have your work', $html);
    }

    // =================================================== logging (#94)

    public function test_a_discarded_transaction_leaves_a_line_because_it_leaves_no_row(): void
    {
        config(['security.logging.rollbacks' => true]);

        /*
         | A connection of its own, on purpose. RefreshDatabase holds the test
         | inside a transaction, so anything opened on the default connection is
         | a SAVEPOINT — and the listener deliberately ignores savepoint
         | rollbacks, because a nested rollback inside a transaction that then
         | commits is control flow, not a failure. Only the outermost one means
         | "the work is gone", which is what has to be logged.
         */
        config(['database.connections.rollback_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]]);

        $probe = DB::connection('rollback_probe');

        try {
            $probe->transaction(function (): void {
                throw new RuntimeException('half a sale is worse than none');
            });
        } catch (RuntimeException) {
            // The rollback is the subject; the exception is only how we cause it.
        }

        $this->assertSame(0, $probe->transactionLevel());

        // Nothing was written and nothing remains to be found — which is why
        // this file is the only evidence the attempt ever happened.
        $this->assertStringContainsString('database transaction failed', $this->readLog($this->financialLog));
    }

    public function test_nothing_secret_ever_reaches_a_log(): void
    {
        $logger = app(SecurityLogger::class);

        $clean = $logger->redact([
            'email' => 'shop@example.test',
            'password' => 'hunter2',
            'payment' => [
                'method' => 'card',
                'card_number' => '4111111111111111',
                'cvv' => '123',
            ],
            'product_name' => 'Token Ring 500ml',
        ]);

        $this->assertSame('[redacted]', $clean['password']);
        $this->assertSame('[redacted]', $clean['payment']['card_number'], 'Redaction has to reach nested keys.');
        $this->assertSame('[redacted]', $clean['payment']['cvv']);

        // Matching is on the KEY, never the value: a heuristic on values would
        // both miss a weak password and mangle an honest product name.
        $this->assertSame('shop@example.test', $clean['email']);
        $this->assertSame('Token Ring 500ml', $clean['product_name']);
        $this->assertSame('card', $clean['payment']['method']);
    }

    public function test_a_failed_login_does_not_flash_the_password_into_the_session(): void
    {
        $this->post(route('login.store'), [
            'email' => $this->owner->email,
            'password' => 'not-the-password',
        ]);

        // Old input is flashed so a form can be refilled. A password flashed
        // into the session store is a password written to disk — by the very
        // request that went wrong.
        $this->assertNull(session('_old_input.password'));
        $this->assertSame($this->owner->email, session('_old_input.email'));
    }

    public function test_a_refusal_is_logged_as_a_refusal_not_as_a_crash(): void
    {
        $cashier = User::factory()->for($this->business)->create(['is_business_owner' => false]);

        // Opening a URL you had no link to is exactly what is worth knowing
        // about, and exactly what an error log full of them would hide.
        //
        // Deliberately a route with NO model binding: a bound route would 404
        // on the missing record long before the permission gate ran, and the
        // test would pass while proving nothing.
        $this->actingAs($cashier)->get(route('app.branches.index'));

        $log = $this->readLog($this->securityLog);

        $this->assertStringContainsString('permission.denied', $log);
        $this->assertStringContainsString('WARNING', $log, 'A working defence is not an application error.');
        $this->assertStringNotContainsString('ERROR', $log);
    }

    // ================================================== throttles (#65, #100)

    public function test_sign_up_cannot_be_hammered(): void
    {
        config([
            'security.throttle.register_max_attempts' => 3,
            'security.throttle.register_decay_minutes' => 60,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('register.store'), ['email' => "spam{$i}@example.test"])
                ->assertStatus(302);
        }

        // The fourth is refused outright. This endpoint creates a business, a
        // user and a subscription without anybody signing in.
        $this->post(route('register.store'), ['email' => 'spam4@example.test'])
            ->assertStatus(429);
    }

    public function test_search_has_a_ceiling_well_above_a_fast_typist(): void
    {
        config(['security.throttle.search_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->owner)->get(route('app.search', ['q' => 'cola']))->assertOk();
        }

        $this->actingAs($this->owner)->get(route('app.search', ['q' => 'cola']))->assertStatus(429);
    }

    public function test_the_throttle_is_read_from_config_at_request_time(): void
    {
        // A limit captured at boot is one nobody can tune in production and one
        // no test can prove fires — so it silently becomes decoration.
        config(['security.throttle.search_per_minute' => 1]);

        $this->actingAs($this->owner)->get(route('app.search', ['q' => 'a']))->assertOk();
        $this->actingAs($this->owner)->get(route('app.search', ['q' => 'b']))->assertStatus(429);
    }
}
