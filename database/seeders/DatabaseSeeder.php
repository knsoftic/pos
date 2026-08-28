<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Admin;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed baseline accounts for local development / verification.
     * (Full demo dataset — products, sales, etc. — comes in Phase 15. #112/#114.)
     * NOTE: demo credentials are for dev only and must be changed for production. #191
     */
    public function run(): void
    {
        // ---- Plan catalogue (feature/limit registries first) ----
        $this->call([
            FeatureSeeder::class,
            LimitSeeder::class,
            PlanSeeder::class,
        ]);

        // ---- Super admin (SaaS operator) — /admin panel ----
        Admin::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@pos.test',
        ]);

        // ---- Demo business #1 + its users — /app panel ----
        $store = Business::factory()->create([
            'name' => 'Demo Retail Store',
            'slug' => 'demo-retail',
            'email' => 'store@demo.test',
        ]);

        User::factory()->create([
            'business_id' => $store->id,
            'name' => 'Store Owner',
            'email' => 'owner@demo.test',
            'is_business_owner' => true,
        ]);

        User::factory()->create([
            'business_id' => $store->id,
            'name' => 'Cashier One',
            'email' => 'cashier@demo.test',
        ]);

        // ---- Demo business #2 (proves data isolation from #1) ----
        $shop = Business::factory()->create([
            'name' => 'Second Shop',
            'slug' => 'second-shop',
            'email' => 'shop@demo.test',
        ]);

        User::factory()->create([
            'business_id' => $shop->id,
            'name' => 'Second Owner',
            'email' => 'owner2@demo.test',
            'is_business_owner' => true,
        ]);

        $this->seedSubscriptions($store, $shop);

        // Branches, tills and the starting roles — through the same provisioner
        // the operator console uses, so a seeded tenant is shaped exactly like a
        // real one (#47, #49, #51).
        $this->seedOrganization($store, $shop);

        $this->command?->info('Seeded accounts (password = "password"):');
        $this->command?->info('  Super admin  → superadmin@pos.test   (/admin/login)');
        $this->command?->info('  Business #1  → owner@demo.test        (/login)  [Professional, paid]');
        $this->command?->info('  Business #2  → owner2@demo.test       (/login)  [Starter, trial]');
        $this->command?->info('  Employee     → cashier@demo.test      (/login)  [Cashier role, Main Branch]');
    }

    /**
     * Provisioning runs AFTER the subscriptions on purpose: the branch and
     * counter services check the plan's features and quotas, so a tenant with no
     * entitlement yet would be refused its own first branch.
     */
    protected function seedOrganization(Business $store, Business $shop): void
    {
        $provisioner = app(OrganizationProvisioner::class);

        $provisioner->provision($store);
        $provisioner->provision($shop);

        // Put the demo cashier on the Cashier role and the main branch, so the
        // permission gates and the branch scope both have something to show.
        $cashierRole = Role::query()->forBusiness($store->id)->where('slug', 'cashier')->first();
        $mainBranch = $store->branches()->where('is_main', true)->first();

        User::query()
            ->forBusiness($store->id)
            ->where('email', 'cashier@demo.test')
            ->update([
                'role_id' => $cashierRole?->id,
                'branch_id' => $mainBranch?->id,
                'max_discount_percent' => 10,
            ]);
    }

    /**
     * Give the demo businesses two deliberately different entitlements, so the
     * feature gates and usage meters are visibly doing something the moment you
     * log in — one paid tier, one trial on a smaller plan.
     *
     * Routed through the real service rather than inserting rows, so the seeded
     * state is exactly what the app produces (audit trail included).
     */
    protected function seedSubscriptions(Business $store, Business $shop): void
    {
        $subscriptions = app(SubscriptionService::class);

        $professional = Plan::query()->where('slug', 'professional')->first();
        $starter = Plan::query()->where('slug', 'starter')->first();

        if ($professional !== null) {
            $subscriptions->assign($store, $professional, BillingCycle::Yearly);
        }

        if ($starter !== null) {
            $subscriptions->startTrial($shop, $starter);
        }
    }
}
