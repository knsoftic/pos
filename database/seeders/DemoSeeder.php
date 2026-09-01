<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessFeatureOverride;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\CustomerLedgerService;
use App\Services\CustomerService;
use App\Services\FeatureService;
use App\Services\InventoryService;
use App\Services\OrganizationProvisioner;
use App\Services\ProductService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SubscriptionService;
use App\Services\SupplierLedgerService;
use App\Services\SupplierService;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * The demo shop: two businesses, their staff, catalogue, parties and purchases
 * (#112, #114).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ WHY THIS IS NO LONGER PART OF `DatabaseSeeder`
 *
 * It used to be. Which meant `php artisan migrate --seed` on a production
 * server — the most natural command in the world to type after a deploy —
 * created a super admin at `superadmin@pos.test` whose password is the word
 * "password", on a box facing the internet.
 *
 * Nothing warned you. The seeder had a comment saying demo credentials are for
 * development only (#191), and a comment is not a control.
 *
 * Now the split is structural: `DatabaseSeeder` plants only what a real
 * installation needs, and this runs when the environment is not production, or
 * when somebody deliberately says otherwise.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Everything here is posted through the SERVICES, never written straight to the
 * tables. That is slower and worth it: the seeded state is one the application
 * could actually have produced, so the statements foot, the stock reconciles,
 * and `pos:check-integrity` passes on a fresh install. A seeder that writes
 * balance columns directly produces a demo that the app itself would call
 * corrupt.
 */
class DemoSeeder extends Seeder
{
    /**
     * Set `ALLOW_DEMO_SEED=true` to force this in production — for a staging
     * box that reports itself as production, which is the only honest reason.
     */
    public function run(): void
    {
        if (app()->isProduction() && ! env('ALLOW_DEMO_SEED', false)) {
            $this->command?->warn('Demo data skipped: this is production. Set ALLOW_DEMO_SEED=true to override. (#191)');

            return;
        }

        // Standalone-safe: `--class=DemoSeeder` on an empty database has to
        // work, and all three of these are idempotent.
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

        // A handful of real catalogue rows, so the product screens have
        // something to show the moment you log in (#112).
        $this->seedCatalog($store);

        // The people the shop trades with, and enough account history for the
        // statements to have something to foot (#39, #38).
        $this->seedParties($store);

        // A delivery that arrived and one that is still outstanding, so the
        // purchase screens have both states to show (#35, #36).
        $this->seedPurchases($store);

        $this->command?->info('Seeded accounts (password = "password"):');
        $this->command?->info('  Super admin  → superadmin@pos.test   (/admin/login)');
        $this->command?->info('  Business #1  → owner@demo.test        (/login)  [Professional, paid]');
        $this->command?->info('  Business #2  → owner2@demo.test       (/login)  [Starter, trial]');
        $this->command?->info('  Employee     → cashier@demo.test      (/login)  [Cashier role, Main Branch]');
    }

    /**
     * Customers and suppliers with real account history (#39, #38, #41, #42).
     *
     * Every figure is posted through the ledger services rather than written to
     * the balance column, so the seeded state is one the app could actually have
     * produced — and the statements foot to the balances.
     */
    protected function seedParties(Business $store): void
    {
        app(TenantContext::class)->runFor($store, function (): void {
            $customers = app(CustomerService::class);
            $customerLedger = app(CustomerLedgerService::class);

            // An account customer who owes money.
            $ayesha = $customers->create([
                'name' => 'Ayesha Traders',
                'phone' => '0300 1234567',
                'city' => 'Lahore',
                'credit_limit' => 50000,
                'opening_balance' => 12000,
                'opening_balance_date' => now()->subMonths(2)->toDateString(),
            ]);

            $customerLedger->chargeSale($ayesha, 18500, [
                'entry_date' => now()->subDays(21)->toDateString(),
                'description' => 'Monthly order',
                'reference_no' => 'INV-1041',
            ]);

            $customerLedger->recordPayment($ayesha->fresh(), 20000, [
                'entry_date' => now()->subDays(9)->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference_no' => 'TRF-8890',
            ]);

            $customerLedger->recordReturn($ayesha->fresh(), 1500, [
                'entry_date' => now()->subDays(4)->toDateString(),
                'description' => 'Two damaged cases returned',
            ]);

            // A walk-in: cash only, nothing outstanding.
            $customers->create([
                'name' => 'Walk-in Customer',
                'phone' => '—',
                'notes' => 'The default account for cash sales.',
            ]);

            // One in credit, from a deposit.
            $deposit = $customers->create([
                'name' => 'Hassan Catering',
                'phone' => '0321 7654321',
                'city' => 'Lahore',
                'credit_limit' => 20000,
            ]);

            $customerLedger->recordPayment($deposit, 5000, [
                'entry_date' => now()->subDays(6)->toDateString(),
                'payment_method' => 'cash',
                'description' => 'Deposit for next month',
            ]);

            // ---- suppliers -------------------------------------------------
            $suppliers = app(SupplierService::class);
            $supplierLedger = app(SupplierLedgerService::class);

            $metro = $suppliers->create([
                'name' => 'Metro Cash & Carry',
                'contact_person' => 'Imran Sheikh',
                'phone' => '042 111 222 333',
                'city' => 'Lahore',
                'payment_terms_days' => 30,
                'opening_balance' => 45000,
                'opening_balance_date' => now()->subMonths(2)->toDateString(),
            ]);

            $supplierLedger->recordPurchase($metro, 62000, [
                'entry_date' => now()->subDays(18)->toDateString(),
                'description' => 'Beverages restock',
                'reference_no' => 'PO-2207',
            ]);

            $supplierLedger->recordPayment($metro->fresh(), 80000, [
                'entry_date' => now()->subDays(5)->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference_no' => 'TRF-4412',
            ]);

            $suppliers->create([
                'name' => 'Gulberg Textiles',
                'contact_person' => 'Nadia Aslam',
                'phone' => '042 999 888 777',
                'city' => 'Lahore',
                'payment_terms_days' => 15,
            ]);
        });
    }

    /**
     * Two purchases: one fully received and part-paid, one still out with the
     * supplier — plus a small return against the first (#35, #36, #37).
     *
     * Posted through the real services, so the stock ledger, the supplier's
     * account and the purchase all tell the same story.
     */
    protected function seedPurchases(Business $store): void
    {
        app(TenantContext::class)->runFor($store, function (): void {
            $purchases = app(PurchaseService::class);

            $supplier = Supplier::query()->where('name', 'Metro Cash & Carry')->first();
            $branch = Branch::query()->where('is_main', true)->first();
            $cola = Product::query()->where('name', 'Cola 500ml')->first();
            $water = Product::query()->where('name', 'Mineral Water 1.5L')->first();

            if ($supplier === null || $branch === null || $cola === null || $water === null) {
                return;
            }

            // ---- delivered, part-paid, with a small return ----------------
            $delivered = $purchases->create([
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'order_date' => now()->subDays(12)->toDateString(),
                'supplier_invoice_no' => 'MC-55120',
            ], [
                ['product_id' => $cola->id, 'quantity_ordered' => 120, 'unit_cost' => 44, 'tax_rate' => 0],
                ['product_id' => $water->id, 'quantity_ordered' => 60, 'unit_cost' => 53, 'tax_rate' => 0],
            ]);

            $purchases->order($delivered);
            $purchases->receive($delivered->fresh(), [], [
                'received_date' => now()->subDays(9)->toDateString(),
                'pay_now' => 5000,
                'payment_method' => 'cash',
            ]);

            $colaLine = $delivered->fresh()->items->firstWhere('product_id', $cola->id);

            if ($colaLine !== null) {
                app(PurchaseReturnService::class)->create($delivered->fresh(), [
                    'reason' => 'Six bottles cracked in transit',
                    'return_date' => now()->subDays(7)->toDateString(),
                ], [$colaLine->id => 6]);
            }

            // ---- still out with the supplier ------------------------------
            $outstanding = $purchases->create([
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'order_date' => now()->subDays(2)->toDateString(),
                'expected_date' => now()->addDays(5)->toDateString(),
            ], [
                ['product_id' => $water->id, 'quantity_ordered' => 90, 'unit_cost' => 53, 'tax_rate' => 0],
            ]);

            $purchases->order($outstanding);
        });
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
     * A small demo catalogue for the first business — one of each product type,
     * so every branch of the UI has something to render.
     *
     * Routed through the real services rather than inserting rows, so the seeded
     * state is exactly what the app produces: generated SKUs, an in-store
     * barcode, and variants with their own codes.
     */
    protected function seedCatalog(Business $store): void
    {
        app(TenantContext::class)->runFor($store, function () use ($store): void {
            $catalog = app(CatalogService::class);
            $products = app(ProductService::class);

            $drinks = $catalog->createCategory(['name' => 'Drinks']);
            $cold = $catalog->createCategory(['name' => 'Cold Drinks', 'parent_id' => $drinks->id]);
            $clothing = $catalog->createCategory(['name' => 'Clothing']);
            $brand = $catalog->createBrand(['name' => 'Acme Beverages']);

            $piece = Unit::query()->where('short_name', 'pc')->first();

            $products->create([
                'name' => 'Cola 500ml',
                'category_id' => $cold->id,
                'brand_id' => $brand->id,
                'unit_id' => $piece?->id,
                'cost_price' => 45.50,
                'selling_price' => 70,
                'alert_quantity' => 24,
                'generate_barcode' => true,
            ]);

            $products->create([
                'name' => 'Mineral Water 1.5L',
                'category_id' => $cold->id,
                'brand_id' => $brand->id,
                'unit_id' => $piece?->id,
                'cost_price' => 55,
                'selling_price' => 80,
                'alert_quantity' => 12,
                'generate_barcode' => true,
            ]);

            $products->create([
                'name' => 'Cotton T-Shirt',
                'type' => 'variable',
                'category_id' => $clothing->id,
                'unit_id' => $piece?->id,
            ], [
                ['options' => ['Size' => 'M', 'Colour' => 'Black'], 'cost_price' => 620, 'selling_price' => 1200, 'generate_barcode' => true],
                ['options' => ['Size' => 'L', 'Colour' => 'Black'], 'cost_price' => 640, 'selling_price' => 1250, 'generate_barcode' => true],
                ['options' => ['Size' => 'L', 'Colour' => 'White'], 'cost_price' => 640, 'selling_price' => 1250],
            ]);

            $products->create([
                'name' => 'Home Delivery',
                'type' => 'service',
                'selling_price' => 250,
            ]);

            /*
             | A perishable, so the demo has something to show on the expiry
             | screen (#34). Professional does not include expiry tracking, so
             | the store gets a per-business OVERRIDE (#10) — which is exactly
             | what overrides are for, and demonstrates both features at once.
             */
            $this->enableExpiryTrackingFor($store);

            $milk = $products->create([
                'name' => 'Fresh Milk 1L',
                'category_id' => $cold->id,
                'unit_id' => $piece?->id,
                'cost_price' => 180,
                'selling_price' => 240,
                'alert_quantity' => 12,
                'tracks_batches' => true,
                'generate_barcode' => true,
            ]);

            $this->seedOpeningStock();
            $this->seedMilkBatches($store, $milk);
        });
    }

    /**
     * Turn on expiry tracking for one business (#10, #34).
     *
     * An operator override rather than a plan change: the demo store is on
     * Professional, and rewriting what Professional includes just to make the
     * seed data richer would quietly change every tenant's entitlements.
     */
    protected function enableExpiryTrackingFor(Business $store): void
    {
        $feature = Feature::query()->where('code', FeatureRegistry::INVENTORY_EXPIRY_TRACKING)->first();

        if ($feature === null) {
            return;
        }

        BusinessFeatureOverride::query()->updateOrCreate(
            ['business_id' => $store->id, 'feature_id' => $feature->id],
            ['is_enabled' => true, 'reason' => 'Trialling perishables for this customer.'],
        );

        app(FeatureService::class)->flush($store);
    }

    /**
     * Three batches of milk: one already gone off, one close, one fine — so the
     * expiry screen has all three states to show on a fresh install.
     */
    protected function seedMilkBatches(Business $store, Product $milk): void
    {
        $branch = Branch::query()->where('is_main', true)->first();

        if ($branch === null) {
            return;
        }

        $inventory = app(InventoryService::class);

        $inventory->createMovement([
            'product' => $milk, 'branch_id' => $branch->id, 'type' => 'purchase',
            'quantity' => 24, 'unit_cost' => 180,
            'batch_number' => 'MLK-2609', 'expiry_date' => now()->addDays(6)->toDateString(),
        ]);

        $inventory->createMovement([
            'product' => $milk, 'branch_id' => $branch->id, 'type' => 'purchase',
            'quantity' => 18, 'unit_cost' => 185,
            'batch_number' => 'MLK-2702', 'expiry_date' => now()->addDays(21)->toDateString(),
        ]);

        // An already-expired batch cannot be received through the normal path —
        // the form refuses a past date — so this one is placed directly.
        $expired = new StockBatch([
            'branch_id' => $branch->id,
            'product_id' => $milk->id,
            'batch_number' => 'MLK-2519',
            'expiry_date' => now()->subDays(3)->toDateString(),
            'unit_cost' => 175,
            'received_at' => now()->subDays(20),
        ]);
        $expired->business_id = $store->id;
        $expired->quantity = 5;
        $expired->save();
    }

    /**
     * Opening stock for the demo catalogue (#152), through the real service so
     * every figure has a ledger line behind it — exactly as it would if the
     * shop had typed it in on their first day.
     *
     * Services are skipped automatically: they carry no stock.
     */
    protected function seedOpeningStock(): void
    {
        $inventory = app(InventoryService::class);

        if (! $inventory->isTrackingEnabled()) {
            return;
        }

        foreach (Product::query()->with('variants')->get() as $product) {
            if (! $product->tracksStock()) {
                continue;
            }

            if ($product->hasVariants()) {
                foreach ($product->variants as $variant) {
                    $inventory->recordOpeningStock($product, random_int(3, 20), (float) $variant->cost_price, $variant->id);
                }

                continue;
            }

            $inventory->recordOpeningStock($product, random_int(10, 60), (float) $product->cost_price);
        }
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
