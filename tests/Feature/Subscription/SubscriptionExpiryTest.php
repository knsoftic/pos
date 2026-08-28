<?php

namespace Tests\Feature\Subscription;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Expiry, grace and trial arithmetic (#11, #127, #174, #176, #186).
 *
 * The thing under test is the rule that access is DERIVED FROM DATES, never read
 * from `subscriptions.status`. A missed cron run leaves stale columns behind; if
 * that column governed access, an expired tenant would keep selling. Several
 * tests below therefore write a deliberately WRONG status and assert the derived
 * answer wins anyway.
 */
class SubscriptionExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->plan = Plan::factory()->monthly()->create();
    }

    /** @param array<string, mixed> $attributes */
    protected function subscription(array $attributes = []): Subscription
    {
        return Subscription::factory()
            ->forBusiness($this->business)
            ->forPlan($this->plan)
            ->create($attributes);
    }

    // ------------------------------------------------------- effective status

    public function test_a_future_end_date_is_active(): void
    {
        $subscription = $this->subscription(['ends_at' => now()->addDays(10)]);

        $this->assertSame(SubscriptionStatus::Active, $subscription->effectiveStatus());
        $this->assertTrue($subscription->grantsAccess());
        $this->assertFalse($subscription->isExpired());
    }

    public function test_a_stale_active_column_does_not_keep_a_lapsed_subscription_alive(): void
    {
        // The exact scenario #79 exists for: the cron never ran, so the column
        // still says active while the dates say otherwise.
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->subDays(30),
            'grace_days' => 0,
        ]);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status, 'precondition: the column is stale');
        $this->assertSame(
            SubscriptionStatus::Expired,
            $subscription->effectiveStatus(),
            'A stale status column granted access to an expired tenant — PAYWALL BROKEN.'
        );
        $this->assertFalse($subscription->grantsAccess());
    }

    public function test_a_stale_expired_column_does_not_lock_out_a_paid_tenant(): void
    {
        // The mirror image, and just as important: nobody who has paid should be
        // locked out because a column was left behind.
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->addDays(20),
        ]);

        $this->assertSame(SubscriptionStatus::Active, $subscription->effectiveStatus());
        $this->assertTrue($subscription->grantsAccess());
    }

    public function test_cancellation_beats_every_other_state(): void
    {
        // Cancelled while the paid period is still running: access stops now.
        $subscription = $this->subscription([
            'ends_at' => now()->addDays(20),
            'trial_ends_at' => now()->addDays(10),
            'cancelled_at' => now(),
        ]);

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->effectiveStatus());
        $this->assertFalse($subscription->grantsAccess());
        $this->assertTrue($subscription->isCancelled());
        $this->assertFalse($subscription->isInGrace(), 'A cancelled subscription must not receive grace.');
        $this->assertFalse($subscription->neverExpires());
    }

    // -------------------------------------------------------------- lifetime

    public function test_a_lifetime_subscription_never_expires(): void
    {
        $subscription = Subscription::factory()
            ->forBusiness($this->business)
            ->forPlan($this->plan)
            ->lifetime()
            ->create();

        $this->assertNull($subscription->ends_at);
        $this->assertSame(SubscriptionStatus::Active, $subscription->effectiveStatus());
        $this->assertTrue($subscription->neverExpires());
        $this->assertNull($subscription->daysRemaining(), 'Unlimited time must be null, not 0.');
        $this->assertNull($subscription->expiryWarningThreshold(), 'A lifetime plan must never warn about expiry.');
        $this->assertFalse($subscription->isInGrace());
    }

    // ------------------------------------------------------------------ grace

    public function test_grace_keeps_access_after_the_paid_period_ends(): void
    {
        $subscription = $this->subscription([
            'ends_at' => now()->subDay(),
            'grace_days' => 3,
        ]);

        $this->assertTrue($subscription->isInGrace());
        $this->assertSame(
            SubscriptionStatus::Active,
            $subscription->effectiveStatus(),
            'Grace must report Active — the tenant keeps working while being warned.'
        );
        $this->assertTrue($subscription->grantsAccess());
        $this->assertSame(0, $subscription->daysRemaining(), 'Days remaining is clamped at 0, never negative.');
        $this->assertSame(2, $subscription->graceDaysRemaining());
    }

    public function test_access_stops_once_grace_runs_out(): void
    {
        $subscription = $this->subscription([
            'ends_at' => now()->subDays(5),
            'grace_days' => 3,
        ]);

        $this->assertFalse($subscription->isInGrace());
        $this->assertSame(SubscriptionStatus::Expired, $subscription->effectiveStatus());
        $this->assertFalse($subscription->grantsAccess());
        $this->assertNull($subscription->graceDaysRemaining(), 'Outside grace there is no grace countdown.');
    }

    public function test_grace_resolution_prefers_the_subscription_then_the_plan_then_the_config(): void
    {
        config()->set('subscription.grace_days', 9);

        $planWithGrace = Plan::factory()->monthly()->graceDays(5)->create();

        // 1. The subscription's own value wins.
        $own = $this->subscription(['grace_days' => 2]);
        $this->assertSame(2, $own->graceDays());

        // 2. Nothing on the subscription → the plan decides.
        $fromPlan = Subscription::factory()
            ->forBusiness($this->business)
            ->forPlan($planWithGrace)
            ->create(['grace_days' => null]);
        $this->assertSame(5, $fromPlan->graceDays());

        // 3. Neither → the operator's system default.
        $fromConfig = $this->subscription(['grace_days' => null]);
        $this->assertSame(9, $fromConfig->graceDays());
    }

    public function test_a_zero_grace_period_is_honoured_rather_than_falling_back(): void
    {
        // 0 is a real answer ("no grace at all"), not "unset". If ?? swallowed it
        // the config default would silently hand out extra days.
        config()->set('subscription.grace_days', 30);

        $subscription = $this->subscription([
            'ends_at' => now()->subHour(),
            'grace_days' => 0,
        ]);

        $this->assertSame(0, $subscription->graceDays());
        $this->assertFalse($subscription->isInGrace());
        $this->assertSame(SubscriptionStatus::Expired, $subscription->effectiveStatus());
    }

    // ------------------------------------------------------------------ trial

    public function test_a_running_trial_reports_trial_and_grants_access(): void
    {
        $subscription = Subscription::factory()
            ->forBusiness($this->business)
            ->forPlan($this->plan)
            ->trial(14)
            ->create();

        $this->assertSame(SubscriptionStatus::Trial, $subscription->effectiveStatus());
        $this->assertTrue($subscription->isOnTrial());
        $this->assertTrue($subscription->grantsAccess());
        $this->assertSame(14, $subscription->daysRemaining());
    }

    public function test_a_finished_trial_with_no_paid_period_expires(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->subDays(4),
            'ends_at' => now()->subDays(4),
            'grace_days' => 0,
        ]);

        $this->assertFalse($subscription->isOnTrial());
        $this->assertSame(SubscriptionStatus::Expired, $subscription->effectiveStatus());
        $this->assertFalse($subscription->grantsAccess());
    }

    public function test_a_trial_running_inside_a_paid_period_still_reports_trial(): void
    {
        // assign() with trial_days: the tenant is on a paid plan but is not being
        // charged yet. Trial must win, or the billing page claims they are paying.
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(7),
            'ends_at' => now()->addDays(30),
        ]);

        $this->assertSame(SubscriptionStatus::Trial, $subscription->effectiveStatus());
        $this->assertSame(30, $subscription->daysRemaining(), 'daysRemaining tracks the paid period, not the trial.');
    }

    // ------------------------------------------------------- warning windows

    public function test_the_warning_threshold_matches_the_tightest_configured_window(): void
    {
        config()->set('subscription.warning_days', [7, 3, 1]);

        $this->assertNull(
            $this->subscription(['ends_at' => now()->addDays(20)])->expiryWarningThreshold(),
            'A subscription far from expiry must not warn.'
        );

        // 5 days out sits inside the 7-day window but not the 3-day one.
        $this->assertSame(7, $this->subscription(['ends_at' => now()->addDays(5)])->expiryWarningThreshold());
        $this->assertSame(3, $this->subscription(['ends_at' => now()->addDays(2)])->expiryWarningThreshold());
        $this->assertSame(1, $this->subscription(['ends_at' => now()->addDay()])->expiryWarningThreshold());
    }

    public function test_expiring_within_finds_only_current_uncancelled_dated_rows(): void
    {
        $service = app(SubscriptionService::class);

        $soon = Business::factory()->create();
        $late = Business::factory()->create();
        $cancelled = Business::factory()->create();
        $replaced = Business::factory()->create();
        $forever = Business::factory()->create();

        Subscription::factory()->forBusiness($soon)->forPlan($this->plan)->expiringIn(4)->create();
        Subscription::factory()->forBusiness($late)->forPlan($this->plan)->expiringIn(40)->create();
        Subscription::factory()->forBusiness($cancelled)->forPlan($this->plan)->expiringIn(4)->cancelled()->create();
        Subscription::factory()->forBusiness($replaced)->forPlan($this->plan)->expiringIn(4)->superseded()->create();
        Subscription::factory()->forBusiness($forever)->forPlan($this->plan)->lifetime()->create();

        $ids = $service->expiringWithin(7)->pluck('business_id')->all();

        $this->assertContains($soon->id, $ids);
        $this->assertNotContains($late->id, $ids, 'A subscription outside the window was reported as expiring.');
        $this->assertNotContains($cancelled->id, $ids, 'A cancelled subscription cannot expire — it already stopped.');
        $this->assertNotContains($replaced->id, $ids, 'A superseded row is history, not a live subscription.');
        $this->assertNotContains($forever->id, $ids, 'A lifetime subscription has no expiry date to warn about.');
    }

    // -------------------------------------------------------- reconciliation

    public function test_reconcile_statuses_brings_stale_columns_back_in_line(): void
    {
        $lapsed = $this->subscription([
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->subDays(30),
            'grace_days' => 0,
        ]);

        $healthy = Subscription::factory()
            ->forBusiness(Business::factory()->create())
            ->forPlan($this->plan)
            ->create(['status' => SubscriptionStatus::Active, 'ends_at' => now()->addDays(10)]);

        $changed = app(SubscriptionService::class)->reconcileStatuses();

        $this->assertSame(1, $changed, 'Only the stale row should have been rewritten.');
        $this->assertSame(SubscriptionStatus::Expired, $lapsed->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $healthy->fresh()->status);
    }

    // --------------------------------------------------- service-level dates

    public function test_extending_a_lapsed_subscription_counts_from_today(): void
    {
        // "+7 days" must mean seven USABLE days. Adding to a date in the past
        // would hand the customer nothing.
        $subscription = $this->subscription([
            'ends_at' => now()->subDays(10),
            'grace_days' => 0,
        ]);

        app(SubscriptionService::class)->extend($this->business, 7, 'Late bank transfer');

        $this->assertSame(7, $subscription->fresh()->daysRemaining());
        $this->assertTrue($subscription->fresh()->grantsAccess());
    }

    public function test_extending_a_live_subscription_adds_to_the_existing_end_date(): void
    {
        $subscription = $this->subscription(['ends_at' => now()->addDays(10)]);

        app(SubscriptionService::class)->extend($this->business, 5);

        $this->assertSame(15, $subscription->fresh()->daysRemaining());
    }

    public function test_extending_a_lifetime_subscription_is_a_no_op(): void
    {
        $subscription = Subscription::factory()
            ->forBusiness($this->business)
            ->forPlan($this->plan)
            ->lifetime()
            ->create();

        app(SubscriptionService::class)->extend($this->business, 30);

        $this->assertNull($subscription->fresh()->ends_at, 'Extending "forever" must not put an end date on it.');
    }

    public function test_renewing_early_starts_where_the_paid_period_ended(): void
    {
        // A customer who pays ahead must not lose the days they already bought.
        $subscription = $this->subscription([
            'billing_cycle' => BillingCycle::Monthly,
            'ends_at' => now()->addDays(10),
        ]);

        $renewed = app(SubscriptionService::class)->renew($this->business);

        $this->assertTrue(
            $renewed->starts_at->isSameDay($subscription->ends_at),
            'An early renewal must begin when the current period ends.'
        );
        $this->assertNotNull($subscription->fresh()->superseded_at, 'The old row must be superseded, not edited.');
        $this->assertNull($renewed->trial_ends_at, 'A renewal is never a trial.');
    }

    public function test_renewing_after_a_lapse_starts_today(): void
    {
        $this->subscription([
            'billing_cycle' => BillingCycle::Monthly,
            'ends_at' => now()->subDays(20),
            'grace_days' => 0,
        ]);

        $renewed = app(SubscriptionService::class)->renew($this->business);

        $this->assertTrue($renewed->starts_at->isToday());
        $this->assertTrue($renewed->grantsAccess());
    }

    public function test_a_plan_change_credits_the_unused_days_by_default(): void
    {
        $this->subscription(['ends_at' => now()->addDays(12)]);

        $target = Plan::factory()->monthly(59.00)->create();

        $changed = app(SubscriptionService::class)
            ->changePlan($this->business, $target, BillingCycle::Monthly);

        $expected = Carbon::now()->addMonth()->addDays(12)->startOfDay();

        $this->assertTrue(
            $changed->ends_at->startOfDay()->equalTo($expected),
            'An upgrade mid-period must carry the unused days forward, not discard them.'
        );
        $this->assertSame($target->id, $changed->plan_id);
    }

    public function test_a_plan_change_can_be_a_clean_cut_over(): void
    {
        $this->subscription(['ends_at' => now()->addDays(12)]);

        $target = Plan::factory()->monthly(59.00)->create();

        $changed = app(SubscriptionService::class)->changePlan(
            $this->business,
            $target,
            BillingCycle::Monthly,
            ['credit_remaining_days' => false],
        );

        $this->assertTrue(
            $changed->ends_at->startOfDay()->equalTo(Carbon::now()->addMonth()->startOfDay()),
            'With crediting off the new period must be exactly one cycle long.'
        );
    }

    public function test_cancelling_stops_access_but_keeps_the_row_visible(): void
    {
        $subscription = $this->subscription(['ends_at' => now()->addDays(20)]);

        app(SubscriptionService::class)->cancel($this->business, 'Customer request');

        $subscription->refresh();

        $this->assertTrue($subscription->isCancelled());
        $this->assertFalse($subscription->grantsAccess());
        $this->assertSame('Customer request', $subscription->cancellation_reason);
        $this->assertNull(
            $subscription->superseded_at,
            'A cancelled subscription stays current — both sides need to see what was cancelled and why.'
        );
    }

    public function test_resuming_recomputes_the_status_from_the_dates(): void
    {
        // Cancelled and ALSO past its end date: resuming must not blindly write
        // "active" onto a subscription that has genuinely run out.
        $subscription = $this->subscription([
            'ends_at' => now()->subDays(10),
            'grace_days' => 0,
        ]);

        $service = app(SubscriptionService::class);
        $service->cancel($this->business);
        $service->resume($this->business);

        $subscription->refresh();

        $this->assertFalse($subscription->isCancelled());
        $this->assertSame(SubscriptionStatus::Expired, $subscription->status);
        $this->assertFalse($subscription->grantsAccess());
    }

    public function test_only_one_subscription_is_ever_current(): void
    {
        $service = app(SubscriptionService::class);

        $service->assign($this->business, $this->plan, BillingCycle::Monthly);
        $service->renew($this->business);
        $service->changePlan($this->business, Plan::factory()->monthly()->create(), BillingCycle::Monthly);

        $current = Subscription::query()
            ->forBusiness($this->business->id)
            ->whereNull('superseded_at')
            ->count();

        $this->assertSame(1, $current, 'Two current subscriptions means two conflicting entitlements.');
        $this->assertSame(3, $service->history($this->business)->count(), 'History is append-only (#176).');
    }

    public function test_the_price_is_snapshotted_so_repricing_a_plan_cannot_rewrite_history(): void
    {
        $subscription = app(SubscriptionService::class)
            ->assign($this->business, $this->plan, BillingCycle::Monthly);

        $this->assertSame('29.00', $subscription->price);

        // The operator raises the list price tomorrow.
        $this->plan->prices()->where('billing_cycle', BillingCycle::Monthly)->update(['price' => 99.00]);

        $this->assertSame(
            '29.00',
            $subscription->fresh()->price,
            'Re-pricing a plan rewrote what a tenant already agreed to pay — #198 violated.'
        );
    }
}
