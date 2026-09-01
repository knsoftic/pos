<?php

namespace Tests\Feature\Security;

use App\Enums\ProductType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\RoleService;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The classic ways in (#100): CSRF, XSS, SQL injection, mass assignment,
 * uploads.
 *
 * ================= WHAT THESE TESTS DEFEND =================
 *  1. THE FRAMEWORK'S DEFENCES ARE ONLY ON IF THEY ARE ON. Laravel escapes,
 *     binds and checks tokens — and every one of those is one `{!!`, one
 *     `whereRaw`, or one `withoutMiddleware` away from not applying. These
 *     tests assert the behaviour, not the intention.
 *  2. A FILE IS WHAT IT CONTAINS, NEVER WHAT IT IS CALLED.
 *  3. INPUT DECIDES DATA, NEVER AUTHORITY. A payload may set a price; it may
 *     never set which business a row belongs to, or who owns the shop.
 */
class AttackSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Target Shop']);
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

        $this->branch = Branch::query()->forBusiness($this->business->id)->firstOrFail();
        $this->owner->refresh();
    }

    // ======================================================== CSRF (#100)

    public function test_a_post_without_a_token_is_refused(): void
    {
        /*
         | Laravel's CSRF middleware short-circuits while `runningUnitTests()`,
         | which is convenient and means the protection is never exercised by
         | the suite that is supposed to prove it. Taking the app out of the
         | testing environment for one request turns it back on — otherwise this
         | test would pass with the middleware removed entirely.
         */
        $this->app['env'] = 'production';

        try {
            $response = $this->withMiddleware(ValidateCsrfToken::class)
                ->post(route('app.categories.store'), ['name' => 'Snacks']);

            $response->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertDatabaseMissing('categories', ['name' => 'Snacks']);
    }

    public function test_the_csrf_middleware_is_actually_in_the_web_stack(): void
    {
        // The test above proves the middleware works. This proves it is fitted:
        // a route group that quietly lost it would leave the other test passing
        // and the application unprotected.
        $this->assertContains(
            ValidateCsrfToken::class,
            app(Kernel::class)->getMiddlewareGroups()['web'],
        );
    }

    // ========================================================= XSS (#100)

    public function test_a_script_tag_in_a_product_name_is_rendered_as_text(): void
    {
        $payload = '<script>alert("xss")</script>';

        app(ProductService::class)->create([
            'name' => $payload,
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
        ]);

        $response = $this->actingAs($this->owner)->get(route('app.products.index'));

        $response->assertOk();
        // Stored verbatim — sanitising on the way IN destroys data and only
        // moves the problem to whichever screen forgot to sanitise.
        $this->assertDatabaseHas('products', ['name' => $payload]);

        // …and escaped on the way OUT, which is where it actually matters.
        $response->assertDontSee($payload, escape: false);
        $response->assertSee('&lt;script&gt;', escape: false);
    }

    public function test_the_only_unescaped_output_in_the_app_is_svg_we_generate(): void
    {
        /*
         | Three `{!! !!}` blocks exist: the barcode from Ean13, the receipt QR
         | from Qr, and the icon component's fixed map. None takes user input.
         | This test walks the views so a fourth cannot be added quietly.
         */
        $allowed = [
            'app/products/label-sheet.blade.php',
            'app/sales/receipt.blade.php',
            'components/icon.blade.php',
        ];

        $found = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains($file->getContents(), '{!!')) {
                $found[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        sort($found);
        sort($allowed);

        $this->assertSame($allowed, $found, 'A new unescaped output needs a reason, and a look.');
    }

    public function test_a_barcode_label_escapes_the_product_name_around_its_svg(): void
    {
        $product = app(ProductService::class)->create([
            'name' => '<img src=x onerror=alert(1)>',
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
            'barcode' => '5901234123457',
        ]);

        $response = $this->actingAs($this->owner)
            ->get(route('app.products.labels', ['products' => [$product->id]]));

        $response->assertOk();
        $response->assertDontSee('<img src=x onerror=alert(1)>', escape: false);
    }

    // ================================================== SQL injection (#100)

    public function test_a_quote_in_a_search_term_is_data_not_syntax(): void
    {
        app(ProductService::class)->create([
            'name' => 'Honest Product',
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
        ]);

        foreach (["' OR '1'='1", "'; DROP TABLE products; --", '" OR 1=1 --'] as $attack) {
            $response = $this->actingAs($this->owner)
                ->get(route('app.products.index', ['search' => $attack]));

            $response->assertOk();
            // Bound as a parameter, so it matches nothing rather than matching
            // everything — the tell that it was treated as syntax.
            $response->assertDontSee('Honest Product');
        }

        $this->assertTrue(Schema::hasTable('products'), 'The table is still there.');
        $this->assertSame(1, Product::query()->count());

        // ⚠️ Without this line the test would pass just as happily against a
        // search that returns nothing for anything at all.
        $this->actingAs($this->owner)
            ->get(route('app.products.index', ['search' => 'Honest']))
            ->assertSee('Honest Product');
    }

    public function test_an_injection_through_global_search_returns_nothing_and_breaks_nothing(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('app.search', ['q' => "x' UNION SELECT password FROM users --"]));

        $response->assertOk();
        $response->assertDontSee('$2y$', escape: false);
        $this->assertTrue(Schema::hasTable('users'));
    }

    // ================================================ mass assignment (#100)

    public function test_a_payload_cannot_move_a_record_into_another_business(): void
    {
        $other = Business::factory()->create(['name' => 'Somebody Else']);

        $this->actingAs($this->owner)->post(route('app.categories.store'), [
            'name' => 'Planted',
            'business_id' => $other->id,
        ])->assertRedirect();

        // The creating hook stamps the ACTIVE tenant and ignores the payload,
        // so this cannot be turned into a write into another shop.
        $this->assertDatabaseHas('categories', [
            'name' => 'Planted',
            'business_id' => $this->business->id,
        ]);

        $this->assertDatabaseMissing('categories', [
            'name' => 'Planted',
            'business_id' => $other->id,
        ]);
    }

    public function test_a_payload_cannot_make_somebody_the_business_owner(): void
    {
        $this->actingAs($this->owner)->post(route('app.employees.store'), [
            'name' => 'Ambitious Cashier',
            'email' => 'ambitious@example.test',
            'password' => 'Sup3r-Secret-Pass',
            'password_confirmation' => 'Sup3r-Secret-Pass',
            'branch_id' => $this->branch->id,
            // Ownership is not a field on a form. There is exactly one owner and
            // it is not decided by whoever can type into a request body.
            'is_business_owner' => 1,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'ambitious@example.test',
            'is_business_owner' => false,
        ]);
    }

    public function test_a_payload_cannot_declare_its_own_stock(): void
    {
        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'Priced By Us',
            'type' => ProductType::Standard->value,
            'selling_price' => 100,
            'cost_price' => 60,
            // Not a field. Stock is the sum of the movement ledger; a product
            // that could declare its own quantity would make the ledger a lie.
            'stock_quantity' => 9999,
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Priced By Us')->firstOrFail();

        $this->assertFalse(Schema::hasColumn('products', 'stock_quantity'));
        $this->assertSame(0.0, app(InventoryService::class)->getAvailableStock($product));
    }

    // ============================================ input at the limit (#100)

    public function test_a_name_at_the_exact_limit_survives_a_slug_collision(): void
    {
        /*
         | ⚠️ A REAL BUG, FOUND BY THIS SUITE FAILING ABOUT ONE RUN IN THREE.
         |
         | Every `uniqueSlug()` slugged the name and then appended `-2` on a
         | collision — without leaving room for the suffix. `roles.name`
         | validates at 60 and `roles.slug` is a 60-character column, so a
         | 60-character name whose slug was taken produced 62 characters and a
         | "Data too long" 500, from a name the form had just accepted.
         |
         | It needed a long name AND a collision, so it looked like flakiness.
         | That is the worst way for a bug to live: rare enough to be shrugged
         | off, certain to reach somebody eventually.
         */
        $name = str_repeat('Stocktaking Supervisor ', 3);
        $name = substr($name, 0, 60);

        $this->actingAs($this->owner);

        $first = app(RoleService::class)->create(['name' => $name], []);
        app(RoleService::class)->delete($first);

        // The trashed row still holds the slug, so the second attempt collides.
        $second = app(RoleService::class)->create(['name' => $name], []);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertLessThanOrEqual(60, strlen($second->slug));
    }

    public function test_a_product_name_at_the_limit_survives_a_collision_too(): void
    {
        $name = substr(str_repeat('Imported Sparkling Mineral Water ', 8), 0, 180);

        $this->actingAs($this->owner);

        $first = app(ProductService::class)->create([
            'name' => $name,
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
        ]);

        app(ProductService::class)->delete($first);

        $second = app(ProductService::class)->create([
            'name' => $name,
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
        ]);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertLessThanOrEqual(180, strlen($second->slug));
    }

    // ===================================================== uploads (#101)

    public function test_a_script_renamed_as_an_image_is_refused(): void
    {
        Storage::fake('public');

        // ⚠️ The whole point: this file is NAMED .jpg and CLAIMS image/jpeg.
        // Both are chosen by the uploader, so neither is evidence.
        $disguised = UploadedFile::fake()->createWithContent(
            'avatar.jpg',
            "<?php system(\$_GET['c']); ?>",
        );

        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'With A Payload',
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
            'image' => $disguised,
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('products', ['name' => 'With A Payload']);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_an_svg_is_refused_even_though_it_is_a_picture(): void
    {
        Storage::fake('public');

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        // An SVG is a script container that happens to render. It is left out
        // of the allowed list on purpose, not by oversight.
        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'Vector Trouble',
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
            'image' => $svg,
        ])->assertSessionHasErrors('image');
    }

    public function test_a_real_image_is_stored_under_a_name_the_uploader_did_not_choose(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'Honest Upload',
            'type' => ProductType::Standard->value,
            'selling_price' => 10,
            'image' => UploadedFile::fake()->image('../../evil.jpg', 400, 400),
        ])->assertRedirect();

        $stored = Storage::disk('public')->allFiles();

        $this->assertCount(1, $stored);
        // A caller who picks the filename picks where the file lands and what
        // it overwrites.
        $this->assertStringNotContainsString('evil', $stored[0]);
        $this->assertStringNotContainsString('..', $stored[0]);
    }
}
