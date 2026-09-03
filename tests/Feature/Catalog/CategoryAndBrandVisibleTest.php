<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Category aur Brand show nahi ho rahe."
 *
 * Making one and never seeing it again is the complaint, so this walks the
 * whole road over HTTP rather than testing the service in isolation: create it
 * on its own screen, then look for it everywhere it is supposed to turn up —
 * its own list, the product form, the POS strip, the products table.
 *
 * ⚠️ A fresh shop is provisioned with NO categories and NO brands. That is the
 * other half of the complaint, and the harder half to see: the dropdown on the
 * product form is not broken when it is empty, it simply has nothing to offer
 * and used to say so in no way at all. Those two cases look identical to
 * whoever is standing at the till, so both are pinned here.
 */
class CategoryAndBrandVisibleTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->for($this->business)->create(['is_business_owner' => true]);

        $plan = Plan::factory()->monthly()->create();

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 50]);
        }

        Subscription::factory()->forBusiness($this->business)->forPlan($plan)->create();

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->owner->refresh();
    }

    // ------------------------------------------------------- the empty shop

    public function test_a_brand_new_shop_starts_with_no_categories_or_brands(): void
    {
        // Not a bug — nobody can guess what a shop sells. But it means the very
        // first visit to the product form shows two dropdowns with nothing in
        // them, which is exactly what "show nahi ho rahe" looks like.
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Brand::query()->count());

        // ⚠️ The asymmetry that explains the whole complaint. Units DO get a
        // default, on the stated reasoning (#195) that a first product should
        // not stop to invent a unit of measure. Categories and brands were left
        // out of that — rightly, because nobody can guess what a shop sells —
        // but nothing was put in their place, so the same screen ends up with
        // one dropdown that works and two that look broken.
        $this->assertSame(1, Unit::query()->count());
        $this->assertSame('Piece', Unit::query()->value('name'));
    }

    public function test_the_empty_dropdowns_say_where_to_go(): void
    {
        // ⚠️ THIS IS THE ACTUAL FIX. An empty <select> is indistinguishable
        // from a broken one, and the screen that fills it is a tab on a
        // DIFFERENT page — so from here there was no road at all. Saying so,
        // with a link, is the whole of it.
        $page = $this->actingAs($this->owner)->get(route('app.products.create'));

        $page->assertOk()
            ->assertSee('No categories yet')
            ->assertSee('No brands yet')
            ->assertSee(route('app.categories.create'), escape: false)
            ->assertSee(route('app.brands.create'), escape: false);
    }

    public function test_the_unit_dropdown_says_the_same_thing_when_it_is_empty(): void
    {
        // Units are seeded, so this is not what a new shop sees — but the same
        // dead end is one deletion away, and leaving one of the three dropdowns
        // silent would read as a bug in that one.
        Unit::query()->delete();

        $this->actingAs($this->owner)->get(route('app.products.create'))
            ->assertOk()
            ->assertSee('No units yet')
            ->assertSee(route('app.units.create'), escape: false);
    }

    public function test_the_hint_goes_away_once_there_is_something_to_choose(): void
    {
        $this->makeCategory('Drinks');
        $this->makeBrand('Nestle');

        $this->actingAs($this->owner)->get(route('app.products.create'))
            ->assertOk()
            ->assertDontSee('No categories yet')
            ->assertDontSee('No brands yet');
    }

    // ------------------------------------------------ create, then find it

    public function test_a_created_category_turns_up_everywhere_it_should(): void
    {
        $this->makeCategory('Drinks');

        // 1. its own list
        $this->actingAs($this->owner)->get(route('app.categories.index'))
            ->assertOk()->assertSee('Drinks');

        // 2. the product form dropdown
        $this->actingAs($this->owner)->get(route('app.products.create'))
            ->assertOk()->assertSee('Drinks');

        // 3. the POS category strip
        $this->actingAs($this->owner)->get(route('app.pos.index'))
            ->assertOk()->assertSee('Drinks');
    }

    public function test_a_created_brand_turns_up_on_its_list_and_the_form(): void
    {
        $this->makeBrand('Nestle');

        $this->actingAs($this->owner)->get(route('app.brands.index'))
            ->assertOk()->assertSee('Nestle');

        $this->actingAs($this->owner)->get(route('app.products.create'))
            ->assertOk()->assertSee('Nestle');
    }

    public function test_a_subcategory_is_shown_under_its_parent_on_the_form(): void
    {
        $parent = $this->makeCategory('Drinks');
        $this->makeCategory('Cold', $parent);

        // "Drinks → Cold", because "Cold" on its own means nothing in a list.
        $this->actingAs($this->owner)->get(route('app.products.create'))
            ->assertOk()->assertSee('Drinks → Cold', escape: false);
    }

    // --------------------------------------------------- switched-off ones

    public function test_an_inactive_category_leaves_the_form_but_keeps_its_list(): void
    {
        $category = $this->makeCategory('Seasonal');

        $this->actingAs($this->owner)->put(route('app.categories.update', $category), [
            'name' => 'Seasonal',
            'sort_order' => 0,
            // no is_active — an unchecked box posts nothing, which is what
            // switching it off actually looks like on the wire.
        ])->assertRedirect();

        $this->assertFalse($category->fresh()->is_active);

        // Still on its own screen, or it could never be switched back on.
        // ⚠️ This has to come FIRST: the redirect left a "Seasonal updated"
        // flash in the session, and whichever page loads next displays it.
        // Asserting the other way round finds that banner and calls it a bug.
        $this->actingAs($this->owner)->get(route('app.categories.index'))
            ->assertOk()->assertSee('Seasonal');

        // Gone from where you file NEW products.
        $this->actingAs($this->owner)->get(route('app.products.create'))
            ->assertOk()->assertDontSee('Seasonal');
    }

    // -------------------------------------------------------- the products list

    public function test_the_products_list_shows_what_a_product_is_filed_under(): void
    {
        $category = $this->makeCategory('Drinks');
        $brand = $this->makeBrand('Nestle');

        $this->actingAs($this->owner)->post(route('app.products.store'), [
            'name' => 'Cola 500ml',
            'type' => 'standard',
            'selling_price' => 100,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'track_inventory' => 1,
        ])->assertRedirect();

        $this->actingAs($this->owner)->get(route('app.products.index'))
            ->assertOk()
            ->assertSee('Cola 500ml')
            ->assertSee('Drinks')
            ->assertSee('Nestle');
    }

    // ------------------------------------------------------------- fixtures

    protected function makeCategory(string $name, ?Category $parent = null): Category
    {
        $this->actingAs($this->owner)->post(route('app.categories.store'), [
            'name' => $name,
            'parent_id' => $parent?->id,
            'sort_order' => 0,
            'is_active' => 1,
        ])->assertRedirect();

        return Category::query()->where('name', $name)->firstOrFail();
    }

    protected function makeBrand(string $name): Brand
    {
        $this->actingAs($this->owner)->post(route('app.brands.store'), [
            'name' => $name,
            'is_active' => 1,
        ])->assertRedirect();

        return Brand::query()->where('name', $name)->firstOrFail();
    }
}
