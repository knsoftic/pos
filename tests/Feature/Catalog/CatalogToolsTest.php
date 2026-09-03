<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Support\BranchContext;
use App\Support\Ean13;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The catalogue's tools: images (#149), barcode labels (#27) and bulk
 * import/export (#150, #151).
 *
 * Three separate risks, tested as three separate things:
 *   - an upload is an execution risk, so what gets stored matters (#101);
 *   - a barcode that scans as the WRONG number is worse than no barcode;
 *   - a half-applied import is worse than a rejected one.
 */
class CatalogToolsTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create(['name' => 'Catalog Tools Shop']);
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);
    }

    /** @param  array<string, bool>  $features */
    protected function setUpBusiness(array $features = [], int $productLimit = 100): void
    {
        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => $features[$feature->code] ?? true]);
        }

        foreach ([
            LimitRegistry::PRODUCTS => $productLimit,
            LimitRegistry::CATEGORIES => 50,
            LimitRegistry::BRANDS => 50,
            LimitRegistry::BRANCHES => 10,
            LimitRegistry::POS_COUNTERS => 10,
            LimitRegistry::EMPLOYEES => 10,
        ] as $code => $value) {
            $plan->limits()->attach(Limit::query()->where('code', $code)->firstOrFail()->id, ['value' => $value]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);
    }

    // ==================================================== EAN-13 (#27)

    public function test_the_check_digit_is_calculated_the_way_the_standard_says(): void
    {
        // A well-known real barcode: 5901234123457.
        $this->assertSame(7, Ean13::checkDigit('590123412345'));
        $this->assertTrue(Ean13::isValid('5901234123457'));

        // One digit off and it must be rejected, not "close enough".
        $this->assertFalse(Ean13::isValid('5901234123456'));
        $this->assertFalse(Ean13::isValid('12345'));
        $this->assertFalse(Ean13::isValid('abcdefghijklm'));
        $this->assertFalse(Ean13::isValid(null));
    }

    public function test_the_bar_pattern_is_the_right_shape(): void
    {
        $bits = Ean13::pattern('5901234123457');

        $this->assertSame(95, strlen($bits), 'EAN-13 is exactly 95 modules.');
        $this->assertSame('101', substr($bits, 0, 3), 'start guard');
        $this->assertSame('01010', substr($bits, 45, 5), 'centre guard');
        $this->assertSame('101', substr($bits, -3), 'end guard');
    }

    public function test_the_first_digit_is_carried_by_parity_not_by_bars(): void
    {
        // Same last twelve digits, different first digit → different bars, even
        // though the drawn digits are identical. Getting this wrong is how a
        // renderer produces a barcode that scans as another product.
        $a = Ean13::pattern('0012345678905');
        $b = Ean13::pattern('9012345678906');

        $this->assertNotSame('', $a);
        $this->assertNotSame('', $b);
        $this->assertNotSame($a, $b);
    }

    public function test_an_invalid_code_renders_nothing_rather_than_wrong_bars(): void
    {
        $this->assertSame('', Ean13::pattern('5901234123456'));
        $this->assertSame('', Ean13::svg('not-a-barcode'));
    }

    public function test_the_svg_is_a_real_svg(): void
    {
        $svg = Ean13::svg('5901234123457');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0 113 79"', $svg);
        $this->assertStringContainsString('5901234123457', $svg, 'The human-readable number belongs on the label.');
    }

    public function test_the_generated_barcodes_are_valid(): void
    {
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Generated', 'type' => ProductType::Standard->value,
            'selling_price' => 100, 'generate_barcode' => true,
        ]);

        $this->assertTrue(Ean13::isValid($product->barcode),
            'A code we mint ourselves must scan — otherwise the label is a sticker.');
        $this->assertStringStartsWith('2', $product->barcode,
            'In-store codes use the restricted-circulation prefix so they cannot collide with a real product.');
    }

    public function test_the_label_sheet_prints_the_chosen_quantities(): void
    {
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'selling_price' => 70, 'generate_barcode' => true,
        ]);

        $this->actingAs($this->owner)->get(route('app.products.labels'))->assertOk()->assertSee('Cola 500ml');

        $response = $this->actingAs($this->owner)->post(route('app.products.labels.sheet'), [
            'labels' => [$product->id => 3],
            'show_name' => true,
            'show_price' => true,
        ]);

        $response->assertOk();
        $this->assertSame(3, substr_count($response->getContent(), '<div class="label">'));
    }

    public function test_ticking_generate_replaces_a_code_that_cannot_be_drawn(): void
    {
        /*
         | ⚠️ "Barcode mein lines show nahi ho rahi."
         |
         | Only a valid EAN-13 can be drawn as bars. A supplier's own code, a
         | 12-digit UPC, anything hand-typed -- none of those can, so the label
         | prints the number alone. That part is deliberate: bars that scan as
         | some OTHER product are far worse than no bars.
         |
         | The trap is the way out. The edit form arrives with the barcode field
         | ALREADY FILLED with the existing code, so ticking "Generate one for
         | me" used to do nothing at all -- the pre-filled value counted as a
         | request and won. The checkbox said one thing and did another, and the
         | shop was left with a label that never grows bars however many times
         | they ask for one.
         |
         | An explicit tick is an instruction. It wins.
         */
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Imported Biscuits', 'type' => ProductType::Standard->value,
            'selling_price' => 120, 'barcode' => 'SUPPLIER-77',
        ]);

        $this->assertSame('', Ean13::svg($product->barcode), 'A supplier code cannot be drawn — that is the premise.');

        // Exactly what the edit form posts: the old code still in the box,
        // plus the box ticked.
        app(ProductService::class)->update($product, [
            'name' => 'Imported Biscuits',
            'type' => ProductType::Standard->value,
            'selling_price' => 120,
            'barcode' => 'SUPPLIER-77',
            'generate_barcode' => true,
        ]);

        $product->refresh();

        $this->assertNotSame('SUPPLIER-77', $product->barcode);
        $this->assertTrue(Ean13::isValid($product->barcode));
        $this->assertNotSame('', Ean13::svg($product->barcode), 'Now it draws.');
    }

    public function test_the_sheet_says_which_labels_will_have_no_bars(): void
    {
        /*
         | Without this the page gives no reason at all: half the labels come
         | out with bars and half with a bare number, and the only thing anybody
         | can report is "barcode mein lines nahi aa rahi".
         |
         | The reason is that only a valid EAN-13 can be drawn. Inventing bars
         | for a supplier's own code would produce a label that scans as some
         | OTHER product -- worse than printing none.
         */
        $this->setUpBusiness();

        $drawable = app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'selling_price' => 70, 'generate_barcode' => true,
        ]);

        $plain = app(ProductService::class)->create([
            'name' => 'Imported Biscuits', 'type' => ProductType::Standard->value,
            'selling_price' => 120, 'barcode' => 'SUPPLIER-77',
        ]);

        $html = $this->actingAs($this->owner)->post(route('app.products.labels.sheet'), [
            'labels' => [$drawable->id => 2, $plain->id => 2],
        ])->assertOk()->getContent();

        $this->assertStringContainsString('1 product will print without bars', $html);
        $this->assertStringContainsString('Imported Biscuits', $html);

        // ⚠️ On the SCREEN only. A label goes onto a tin of ghee; an
        // explanation printed on it would be nonsense on a shelf.
        $this->assertStringContainsString('.notice { display: none; }', $html);
    }

    public function test_a_sheet_where_everything_draws_says_nothing(): void
    {
        // A warning that appears when there is nothing wrong is a warning
        // people stop reading.
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'selling_price' => 70, 'generate_barcode' => true,
        ]);

        $html = $this->actingAs($this->owner)->post(route('app.products.labels.sheet'), [
            'labels' => [$product->id => 2],
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString('will print without bars', $html);
    }

    public function test_a_typed_barcode_is_still_kept_when_generate_is_not_asked_for(): void
    {
        // The tick is the only thing that overrides a typed code. Untouched,
        // a supplier's barcode stays exactly as it is — it is the number on
        // the box, and the shop scans the real one.
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Imported Biscuits', 'type' => ProductType::Standard->value,
            'selling_price' => 120, 'barcode' => '5901234123457',
        ]);

        $this->assertSame('5901234123457', $product->barcode);

        app(ProductService::class)->update($product, [
            'name' => 'Imported Biscuits',
            'type' => ProductType::Standard->value,
            'selling_price' => 120,
            'barcode' => '5901234123457',
        ]);

        $this->assertSame('5901234123457', $product->fresh()->barcode);
    }

    public function test_pressing_print_with_nothing_typed_is_not_an_error(): void
    {
        /*
         | ⚠️ THE MOST LIKELY THING TO HAPPEN ON THIS SCREEN. Every row posts a
         | quantity box and all of them start blank, so pressing the button
         | first and reading the form second is ordinary behaviour.
         |
         | It used to abort(422) -- and 422 had no error view, so it fell
         | through to Symfony's own page: "Something is broken. Please let us
         | know what you were doing." Nothing was broken. Telling a shopkeeper
         | their software is faulty because they have not finished typing
         | teaches them to ignore the next error, which will be a real one.
         */
        $this->setUpBusiness();

        app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'selling_price' => 70, 'generate_barcode' => true,
        ]);

        $response = $this->actingAs($this->owner)->post(route('app.products.labels.sheet'), [
            'labels' => [],
        ]);

        // ⚠️ Sent somewhere, not just refused. The form opens in a NEW TAB, so
        // that tab has no history -- "go back" is not available to the person
        // reading it, and a bare 422 would strand them on a dead page.
        $response->assertRedirect(route('app.products.labels'));
        $response->assertSessionHas('error');
        $this->assertStringNotContainsString('Something is broken', (string) $response->getContent());
    }

    public function test_a_shop_with_no_barcodes_is_told_that_and_not_blamed(): void
    {
        /*
         | ⚠️ THE MESSAGE MUST FIT THE SITUATION. With no barcoded product the
         | table is empty -- there is no box to type a number into -- so
         | "type how many labels you need beside a product" sends somebody
         | hunting the screen for a field that does not exist. They conclude the
         | page is broken, and from where they are sitting that is reasonable.
         */
        $this->setUpBusiness();

        app(ProductService::class)->create([
            'name' => 'No Barcode Here', 'type' => ProductType::Standard->value,
            'selling_price' => 70,
        ]);

        $response = $this->actingAs($this->owner)
            ->post(route('app.products.labels.sheet'), ['labels' => []]);

        $response->assertRedirect(route('app.products.labels'));
        $this->assertStringContainsString('No product has a barcode yet', (string) session('error'));
        $this->assertStringNotContainsString('beside a product', (string) session('error'));
    }

    public function test_the_button_is_absent_when_there_is_nothing_to_print(): void
    {
        // A button that can only fail is worse than no button: it invites the
        // click and then blames the person for making it.
        $this->setUpBusiness();

        app(ProductService::class)->create([
            'name' => 'No Barcode Here', 'type' => ProductType::Standard->value,
            'selling_price' => 70,
        ]);

        $this->actingAs($this->owner)->get(route('app.products.labels'))
            ->assertOk()
            ->assertDontSee('Open print sheet')
            ->assertSee('Nothing to print yet');
    }

    public function test_the_button_comes_back_once_something_has_a_barcode(): void
    {
        $this->setUpBusiness();

        app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'selling_price' => 70, 'generate_barcode' => true,
        ]);

        $this->actingAs($this->owner)->get(route('app.products.labels'))
            ->assertOk()
            ->assertSee('Open print sheet')
            ->assertDontSee('Nothing to print yet');
    }

    public function test_all_zeroes_is_treated_the_same_as_nothing(): void
    {
        $this->setUpBusiness();

        $product = app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'selling_price' => 70, 'generate_barcode' => true,
        ]);

        // Typing 0 is a person saying "none of this one", not a fault.
        $this->actingAs($this->owner)
            ->post(route('app.products.labels.sheet'), ['labels' => [$product->id => 0]])
            ->assertRedirect(route('app.products.labels'));
    }

    public function test_a_422_gets_the_applications_own_page_not_symfonys(): void
    {
        /*
         | 422 is used in about a dozen places -- "Only a draft can be edited",
         | "That sale is not on hold" -- and had no view of its own, so every
         | one of them announced that the software was broken over a rule it was
         | enforcing on purpose.
         |
         | The abort message is the useful half and must survive to the page.
         */
        $this->setUpBusiness();

        $view = view('errors.422', [
            'exception' => new HttpException(422, 'Only a draft can be edited.'),
        ])->render();

        $this->assertStringContainsString('Only a draft can be edited.', $view);
        $this->assertStringNotContainsString('Something is broken', $view);
    }

    public function test_the_label_screen_is_plan_gated(): void
    {
        $this->setUpBusiness([FeatureRegistry::POS_BARCODE_SCANNER => false]);

        $this->actingAs($this->owner)->getJson(route('app.products.labels'))->assertStatus(403);
    }

    // ================================================== images (#149, #101)

    public function test_an_uploaded_image_is_stored_with_a_random_name(): void
    {
        Storage::fake('public');
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'With a picture',
            'type' => ProductType::Standard->value,
            'selling_price' => 100,
            'image' => UploadedFile::fake()->image('my-holiday-photo.jpg', 400, 400),
        ])->assertRedirect();

        $product = Product::query()->firstOrFail();

        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);

        $this->assertStringNotContainsString('my-holiday-photo', $product->image_path,
            'The stored name must never be the one the caller chose.');
    }

    public function test_a_script_pretending_to_be_an_image_is_refused(): void
    {
        Storage::fake('public');
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'Nasty',
            'type' => ProductType::Standard->value,
            'selling_price' => 100,
            'image' => UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "pwned";'),
        ])->assertSessionHasErrors('image');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_replacing_an_image_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'Swap', 'type' => ProductType::Standard->value, 'selling_price' => 100,
            'image' => UploadedFile::fake()->image('first.jpg'),
        ]);

        $product = Product::query()->firstOrFail();
        $first = $product->image_path;

        $this->actingAs($this->owner)->put(route('app.products.update', $product), [
            'name' => 'Swap', 'type' => ProductType::Standard->value, 'selling_price' => 100,
            'image' => UploadedFile::fake()->image('second.jpg'),
        ]);

        $product->refresh();

        $this->assertNotSame($first, $product->image_path);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_an_image_can_be_removed(): void
    {
        Storage::fake('public');
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'Bare', 'type' => ProductType::Standard->value, 'selling_price' => 100,
            'image' => UploadedFile::fake()->image('pic.jpg'),
        ]);

        $product = Product::query()->firstOrFail();
        $path = $product->image_path;

        $this->actingAs($this->owner)->put(route('app.products.update', $product), [
            'name' => 'Bare', 'type' => ProductType::Standard->value, 'selling_price' => 100,
            'remove_image' => '1',
        ]);

        $this->assertNull($product->fresh()->image_path);
        Storage::disk('public')->assertMissing($path);
    }

    // ============================================ import / export (#150, #151)

    protected function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $contents);
    }

    public function test_a_csv_creates_products(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.products.import.store'), [
            'file' => $this->csv(
                "name,sku,selling_price,cost_price,category,brand\n".
                "Cola 500ml,COLA-500,70,45,Drinks,Acme\n".
                "Water 1L,WATER-1L,50,30,Drinks,Acme\n"
            ),
        ])->assertRedirect(route('app.products.index'));

        $this->assertSame(2, Product::query()->count());

        $cola = Product::query()->where('sku', 'COLA-500')->firstOrFail();
        $this->assertSame('70.00', $cola->selling_price);
        $this->assertSame('Drinks', $cola->category->name);
        $this->assertSame('Acme', $cola->brand->name);

        // Both rows named the same category and brand — one of each, not two.
        $this->assertSame(1, Category::query()->count());
        $this->assertSame(1, Brand::query()->count());
    }

    public function test_a_matching_sku_updates_rather_than_duplicates(): void
    {
        $this->setUpBusiness();

        app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'sku' => 'COLA-500', 'selling_price' => 70,
        ]);

        $this->actingAs($this->owner)->post(route('app.products.import.store'), [
            'file' => $this->csv("name,sku,selling_price\nCola 500ml,COLA-500,80\n"),
        ])->assertRedirect();

        $this->assertSame(1, Product::query()->count(), 'Re-uploading a corrected list must not duplicate.');
        $this->assertSame('80.00', Product::query()->firstOrFail()->selling_price);
    }

    public function test_one_bad_row_rolls_the_whole_file_back(): void
    {
        $this->setUpBusiness();

        $this->actingAs($this->owner)->post(route('app.products.import.store'), [
            'file' => $this->csv(
                "name,sku,selling_price,unit\n".
                "Good One,GOOD-1,50,\n".
                "Bad One,BAD-1,50,nonexistent-unit\n"
            ),
        ])->assertRedirect();

        $this->assertSame(0, Product::query()->count(),
            'A half-imported catalogue is harder to fix than one that never imported.');
    }

    public function test_a_row_without_a_name_is_reported_with_its_line_number(): void
    {
        $this->setUpBusiness();

        $response = $this->actingAs($this->owner)->post(route('app.products.import.store'), [
            'file' => $this->csv("name,selling_price\n,50\n"),
        ]);

        $response->assertSessionHas('import_errors');
        $this->assertStringContainsString('Line 2', session('import_errors')[0]);
    }

    public function test_the_import_respects_the_product_quota(): void
    {
        $this->setUpBusiness(productLimit: 1);

        $this->actingAs($this->owner)->post(route('app.products.import.store'), [
            'file' => $this->csv("name,selling_price\nOne,10\nTwo,20\nThree,30\n"),
        ]);

        $this->assertSame(0, Product::query()->count(),
            'The quota is checked before anything is written, not at row 481.');
    }

    public function test_a_user_who_cannot_see_cost_cannot_import_it(): void
    {
        $this->setUpBusiness();

        $role = Role::factory()->for($this->business)->withPermissions([
            PermissionRegistry::PRODUCTS_VIEW,
            PermissionRegistry::PRODUCTS_CREATE,
            PermissionRegistry::PRODUCTS_UPDATE,
            PermissionRegistry::PRODUCTS_IMPORT,
        ])->create();

        $importer = User::factory()->for($this->business)->create(['role_id' => $role->id]);

        $this->actingAs($importer)->post(route('app.products.import.store'), [
            'file' => $this->csv("name,sku,selling_price,cost_price\nSneaky,SNEAK-1,100,999\n"),
        ])->assertRedirect();

        $this->assertSame('0.0000', Product::query()->firstOrFail()->cost_price,
            'The cost column is ignored for someone who may not see cost (#52).');
    }

    public function test_the_export_streams_the_catalogue(): void
    {
        $this->setUpBusiness();

        app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'sku' => 'COLA-500', 'cost_price' => 45, 'selling_price' => 70,
        ]);

        $response = $this->actingAs($this->owner)->get(route('app.products.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Cola 500ml', $csv);
        $this->assertStringContainsString('COLA-500', $csv);
        $this->assertStringContainsString('cost_price', $csv);
    }

    public function test_the_export_hides_cost_from_someone_who_may_not_see_it(): void
    {
        $this->setUpBusiness();

        app(ProductService::class)->create([
            'name' => 'Cola 500ml', 'type' => ProductType::Standard->value,
            'cost_price' => 45, 'selling_price' => 70,
        ]);

        $role = Role::factory()->for($this->business)->withPermissions([
            PermissionRegistry::PRODUCTS_VIEW,
            PermissionRegistry::REPORTS_EXPORT,
        ])->create();

        $exporter = User::factory()->for($this->business)->create(['role_id' => $role->id]);

        $csv = $this->actingAs($exporter)->get(route('app.products.export'))->streamedContent();

        $this->assertStringContainsString('Cola 500ml', $csv);
        $this->assertStringNotContainsString('cost_price', $csv,
            'An export is the easiest possible way to walk out with margins.');
    }

    public function test_importing_is_plan_gated_and_exporting_is_permission_gated(): void
    {
        $this->setUpBusiness([FeatureRegistry::CATALOG_IMPORT => false]);

        $this->actingAs($this->owner)
            ->postJson(route('app.products.import.store'), ['file' => $this->csv("name\nX\n")])
            ->assertStatus(403);

        $viewer = User::factory()->for($this->business)->create([
            'role_id' => Role::factory()->for($this->business)
                ->withPermissions([PermissionRegistry::PRODUCTS_VIEW])->create()->id,
        ]);

        $this->actingAs($viewer)->getJson(route('app.products.export'))->assertStatus(403);
    }

    public function test_the_template_download_shows_the_format(): void
    {
        $this->setUpBusiness();

        $csv = $this->actingAs($this->owner)->get(route('app.products.import.template'))->streamedContent();

        $this->assertStringContainsString('name,sku,barcode', $csv);
        $this->assertStringContainsString('Cola 500ml', $csv);
    }
}
