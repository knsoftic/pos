<?php

namespace Tests\Feature\Subscription;

use App\Enums\PlanRequestStatus;
use App\Models\Admin;
use App\Models\Business;
use App\Models\Feature;
use App\Models\Limit;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\PlatformSettingsService;
use App\Support\BranchContext;
use App\Support\TenantContext;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\LimitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "I want this plan" — from the shop's button to the operator's screen (#82).
 *
 * ================= WHAT WAS WRONG =================
 * The button was a `mailto:`. That needs a mail client configured on the
 * shopkeeper's device; on a phone without one it does nothing whatsoever, and
 * even when it works the ask exists only inside somebody's inbox. Nothing
 * anywhere recorded that the shop had asked, so "request nahi ja rahi" was both
 * literally true and impossible to verify after the fact.
 *
 * ================= WHAT IT MUST NOT BECOME =================
 * ⚠️ A REQUEST IS NOT A PURCHASE. Nothing here may move a shop onto a plan or
 * touch a subscription. There is no checkout in this release; an upgrade button
 * that silently granted a paid plan without money would be a far worse bug than
 * the dead one it replaced. Several tests below exist only to hold that line.
 */
class PlanRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected Admin $admin;

    protected Plan $starter;

    protected Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->seed(LimitSeeder::class);

        $this->admin = Admin::factory()->create();
        $this->business = Business::factory()->create(['name' => 'Rahat Kiryana Store']);
        $this->owner = User::factory()->for($this->business)
            ->create(['is_business_owner' => true, 'name' => 'Rahat Ali']);

        $this->starter = $this->plan('Starter', 1000);
        $this->pro = $this->plan('Professional', 2500);

        Subscription::factory()->forBusiness($this->business)->forPlan($this->starter)
            ->create(['price' => 1000, 'currency' => 'PKR']);

        app(OrganizationProvisioner::class)->provision($this->business);
        app(TenantContext::class)->setBusiness($this->business);
        app(BranchContext::class)->forUser($this->owner);

        $this->owner->refresh();
    }

    protected function plan(string $name, float $price, array $attributes = []): Plan
    {
        $plan = Plan::factory()->monthly($price)->create(['name' => $name] + $attributes);

        foreach (Feature::query()->get() as $feature) {
            $plan->features()->attach($feature->id, ['is_enabled' => true]);
        }

        foreach (Limit::query()->get() as $limit) {
            $plan->limits()->attach($limit->id, ['value' => 100]);
        }

        return $plan;
    }

    // ══════════════════════════════════════════════════ the shop's side

    public function test_the_button_is_a_real_form_not_a_mailto(): void
    {
        // ⚠️ The whole defect in one assertion. A mailto: needs a configured
        // mail client; a form needs a browser.
        $page = $this->actingAs($this->owner)->get(route('app.billing.plans'))->assertOk();

        $page->assertSee(route('app.billing.plans.request', $this->pro), escape: false);

        // Narrow on purpose: the page footer carries a legitimate mailto: to
        // support. What must be gone is the one that WAS the request button.
        $page->assertDontSee('Plan change request', escape: false);
    }

    public function test_asking_for_a_plan_files_a_request(): void
    {
        $this->actingAs($this->owner)
            ->post(route('app.billing.plans.request', $this->pro))
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = PlanRequest::query()->allTenants()->firstOrFail();

        $this->assertSame($this->business->id, $request->business_id);
        $this->assertSame($this->pro->id, $request->plan_id);
        $this->assertSame(PlanRequestStatus::Pending, $request->status);

        // Recorded as text, so the row still reads correctly after the person
        // leaves the shop or the plan gets renamed.
        $this->assertSame('Rahat Ali', $request->requested_by_name);
        $this->assertSame('Starter', $request->current_plan_name);
    }

    public function test_asking_does_not_change_the_subscription(): void
    {
        // ⚠️ The line this feature must never cross.
        $before = Subscription::query()->allTenants()->firstOrFail();

        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        $after = Subscription::query()->allTenants()->firstOrFail();

        $this->assertSame($before->plan_id, $after->plan_id);
        $this->assertSame((float) $before->price, (float) $after->price);
    }

    public function test_asking_twice_is_one_conversation_not_two(): void
    {
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        // Somebody who did not hear back is not a second customer.
        $this->assertSame(1, PlanRequest::query()->allTenants()->count());
    }

    public function test_changing_their_mind_replaces_the_ask(): void
    {
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->starter));

        $this->assertSame(1, PlanRequest::query()->allTenants()->count());
        $this->assertSame($this->starter->id, PlanRequest::query()->allTenants()->firstOrFail()->plan_id);
    }

    public function test_the_page_shows_that_the_ask_was_received(): void
    {
        // Without this the shop cannot tell a filed request from a dead button,
        // which is the exact confusion this replaced.
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        $this->actingAs($this->owner)->get(route('app.billing.plans'))
            ->assertOk()
            ->assertSee('Requested');
    }

    public function test_a_private_plan_cannot_be_requested(): void
    {
        // Negotiated plans are for the operator to assign. Letting a shop ask
        // for one by id would turn a private price into a menu item.
        $secret = $this->plan('Negotiated Deal', 500, ['is_public' => false]);

        $this->actingAs($this->owner)
            ->post(route('app.billing.plans.request', $secret))
            ->assertNotFound();

        $this->assertSame(0, PlanRequest::query()->allTenants()->count());
    }

    public function test_one_shop_cannot_see_another_shops_requests(): void
    {
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        $other = Business::factory()->create();
        $otherOwner = User::factory()->for($other)->create(['is_business_owner' => true]);
        Subscription::factory()->forBusiness($other)->forPlan($this->starter)->create();
        app(OrganizationProvisioner::class)->provision($other);

        app(TenantContext::class)->setBusiness($other);

        $this->assertSame(0, PlanRequest::query()->count());
    }

    // ══════════════════════════════════════════════════════ WhatsApp

    public function test_no_number_configured_means_no_whatsapp_button(): void
    {
        app(PlatformSettingsService::class)->put(['brand.whatsapp' => '']);

        $this->actingAs($this->owner)
            ->post(route('app.billing.plans.request', $this->pro))
            ->assertSessionMissing('whatsapp');

        // The request itself is filed regardless — WhatsApp is a second route
        // to the same ask, never the only copy of it.
        $this->assertSame(1, PlanRequest::query()->allTenants()->count());
    }

    public function test_a_configured_number_produces_a_ready_to_send_message(): void
    {
        app(PlatformSettingsService::class)->put(['brand.whatsapp' => '923001234567']);

        $response = $this->actingAs($this->owner)
            ->post(route('app.billing.plans.request', $this->pro));

        $link = session('whatsapp');

        $this->assertIsString($link);
        $this->assertStringStartsWith('https://wa.me/923001234567?text=', $link);

        $text = rawurldecode(substr($link, strpos($link, 'text=') + 5));

        // Everything the operator needs to answer without asking who is calling.
        $this->assertStringContainsString('Rahat Kiryana Store', $text);
        $this->assertStringContainsString('Professional', $text);
        $this->assertStringContainsString('Currently on: Starter', $text);

        $response->assertRedirect();
    }

    public function test_the_banner_says_the_request_is_already_saved(): void
    {
        /*
         | ⚠️ Wording, and it matters. The link opens WhatsApp ON THE
         | SHOPKEEPER'S DEVICE with the text prefilled — nothing is sent from
         | the server, and they still have to press send. If the page implied
         | the message had gone, a shop that closed the tab would sit waiting
         | for a reply to a message nobody ever received.
         */
        app(PlatformSettingsService::class)->put(['brand.whatsapp' => '923001234567']);

        $this->actingAs($this->owner)
            ->post(route('app.billing.plans.request', $this->pro))
            ->assertRedirect();

        $this->actingAs($this->owner)->get(route('app.billing.plans'))
            ->assertOk()
            ->assertSee('Request saved.')
            ->assertSee('It is already with us');
    }

    // ══════════════════════════════════════════════════ the operator's side

    public function test_the_operator_sees_the_request(): void
    {
        /*
         | ⚠️ THE ONE THAT WOULD HAVE CAUGHT THE OBVIOUS MISTAKE. PlanRequest is
         | tenant-scoped, and the admin panel has no tenant in context — so a
         | query without allTenants() comes back empty and the screen says
         | "nothing here", which reads as "no shop has asked" rather than "this
         | list is broken".
         */
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.plan-requests.index'))
            ->assertOk()
            ->assertSee('Rahat Kiryana Store')
            ->assertSee('Professional');
    }

    public function test_the_operators_sidebar_counts_what_is_waiting(): void
    {
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        // A request filed into a screen nobody opens is the old bug with a table.
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Plan requests');
    }

    public function test_the_operator_can_answer_it(): void
    {
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        $request = PlanRequest::query()->allTenants()->firstOrFail();

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.plan-requests.update', $request), ['status' => PlanRequestStatus::Done->value])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(PlanRequestStatus::Done, $request->status);
        $this->assertSame($this->admin->id, $request->handled_by);
        $this->assertNotNull($request->handled_at);
    }

    public function test_answering_a_request_does_not_move_the_shop(): void
    {
        // ⚠️ Marking it done records that the conversation happened. Moving the
        // shop is a separate act, by somebody who has seen the money.
        $this->actingAs($this->owner)->post(route('app.billing.plans.request', $this->pro));

        $request = PlanRequest::query()->allTenants()->firstOrFail();

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.plan-requests.update', $request), ['status' => PlanRequestStatus::Done->value]);

        $this->assertSame(
            $this->starter->id,
            Subscription::query()->allTenants()->firstOrFail()->plan_id,
        );
    }

    public function test_a_shopkeeper_cannot_reach_the_operators_list(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.plan-requests.index'))
            ->assertRedirect();
    }
}
