<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;

/**
 * Somebody signing themselves up (#109).
 *
 * ================= THE SAME PATH THE OPERATOR USES =================
 * A self-signup produces exactly what the admin console produces: a business, an
 * owner, the provisioned organisation, and a subscription. It goes through
 * {@see OrganizationProvisioner} and {@see SubscriptionService} rather than
 * writing its own rows, so a shop created from the website can never be shaped
 * differently from one created by support — which is the bug that would only
 * show up weeks later, in whichever code path forgot the main branch.
 *
 * ================= ALL OF IT, OR NONE =================
 * One transaction. A half-registered account — a business with no owner, or an
 * owner with no branch — is worse than a failed sign-up, because the email
 * address is now taken and the person cannot try again.
 *
 * ================= WHICH PLAN =================
 * The operator names one in settings, or the cheapest public plan is used. It is
 * resolved at the moment of signing up rather than pinned in config, so
 * retiring a plan cannot leave registration pointing at nothing.
 */
class RegistrationService
{
    public function __construct(
        protected OrganizationProvisioner $organization,
        protected SubscriptionService $subscriptions,
        protected AuditService $audit,
    ) {}

    public function isOpen(): bool
    {
        return (bool) config('platform.registration_open', false) && $this->trialPlan() !== null;
    }

    /**
     * How many free days a new sign-up gets, or null if the entry plan is free
     * and there is nothing to try.
     *
     * The marketing pages read this rather than `$plan->trialDays()`, so a free
     * entry plan never advertises a trial it is not going to give.
     */
    public function trialDays(): ?int
    {
        $plan = $this->trialPlan();

        if ($plan === null || $plan->isFree()) {
            return null;
        }

        return $plan->trialDays() > 0 ? $plan->trialDays() : null;
    }

    /**
     * The plan a new sign-up lands on.
     *
     * Null means registration cannot proceed, and the caller has to say so
     * plainly rather than creating an account with no subscription.
     */
    public function trialPlan(): ?Plan
    {
        $configured = config('platform.trial_plan_id');

        if (filled($configured)) {
            $plan = Plan::query()->active()->public()->find((int) $configured);

            if ($plan !== null) {
                return $plan;
            }
        }

        // The cheapest public plan. Resolved now, not pinned — retiring a plan
        // must not break the sign-up form.
        return Plan::query()
            ->active()
            ->public()
            ->ordered()
            ->get()
            ->sortBy(fn (Plan $plan) => $plan->entryPrice()?->price ?? 0)
            ->first();
    }

    /**
     * @param  array{business_name: string, name: string, email: string, phone?: string|null, password: string}  $data
     */
    public function register(array $data): User
    {
        abort_unless(
            (bool) config('platform.registration_open', false),
            403,
            'Sign-ups are closed at the moment.',
        );

        $plan = $this->trialPlan();

        abort_if(
            $plan === null,
            503,
            'Sign-ups are unavailable right now — no plan is published. Please try again shortly.',
        );

        return DB::transaction(function () use ($data, $plan): User {
            $business = new Business([
                'name' => trim($data['business_name']),
                'slug' => $this->uniqueSlug($data['business_name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'status' => 'active',
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => config('app.locale', 'en'),
            ]);
            $business->save();

            // ⚠️ business_id and is_business_owner are guarded (#132). Assigned
            // here explicitly, never mass-assigned from a public form.
            $owner = new User([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $owner->business_id = $business->id;
            $owner->is_business_owner = true;
            $owner->is_active = true;
            $owner->save();

            // Main branch, first till, starting roles — before the subscription,
            // so a tenant is never entitled to a POS it has no counter for.
            $this->organization->provision($business);

            /*
            | ⚠️ A FREE PLAN IS ASSIGNED, NOT TRIALLED.
            |
            | `startTrial()` always produces a subscription with an end date —
            | it is a trial, so it has to end. Putting a free plan through it
            | would give the shop a plan that expires, which is exactly what
            | "free" is supposed not to do, and the owner would be asked to
            | renew something that costs nothing.
            |
            | A paid plan gets the trial. That is the difference between "try
            | this for a fortnight" and "this is yours".
            */
            $plan->isFree()
                ? $this->subscriptions->assign($business, $plan, BillingCycle::Monthly)
                : $this->subscriptions->startTrial($business, $plan);

            $this->audit->log(
                'business.registered',
                $business,
                "\"{$business->name}\" signed up from the website on {$plan->name}.",
                ['owner_email' => $owner->email, 'plan' => $plan->slug],
                null,
                $business->id,
            );

            return $owner;
        });
    }

    /**
     * A readable, unique slug.
     *
     * Checked across ALL businesses including trashed ones: a slug is a public
     * identifier, and handing a new shop the URL of a deleted one would be a
     * surprise for both of them.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Slug::base($name, 255, 'shop');
        $slug = $base;
        $suffix = 2;

        while (Business::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
