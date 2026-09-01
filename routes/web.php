<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessNoteController;
use App\Http\Controllers\Admin\BusinessOverrideController;
use App\Http\Controllers\Admin\BusinessSubscriptionController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\SystemNotificationController;
use App\Http\Controllers\App\BarcodeLabelController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\BranchController;
use App\Http\Controllers\App\BrandController;
use App\Http\Controllers\App\CategoryController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\CustomerLedgerController;
use App\Http\Controllers\App\DashboardController as AppDashboardController;
use App\Http\Controllers\App\EmployeeController;
use App\Http\Controllers\App\ExpenseCategoryController;
use App\Http\Controllers\App\ExpenseController;
use App\Http\Controllers\App\InventoryController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\App\OtherIncomeController;
use App\Http\Controllers\App\PosController;
use App\Http\Controllers\App\PosCounterController;
use App\Http\Controllers\App\ProductController;
use App\Http\Controllers\App\ProductImportController;
use App\Http\Controllers\App\ProfitLossController;
use App\Http\Controllers\App\PurchaseController;
use App\Http\Controllers\App\PurchaseReturnController;
use App\Http\Controllers\App\ReportController;
use App\Http\Controllers\App\RoleController;
use App\Http\Controllers\App\SaleController;
use App\Http\Controllers\App\SaleReturnController;
use App\Http\Controllers\App\SearchController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\StockTransferController;
use App\Http\Controllers\App\SupplierController;
use App\Http\Controllers\App\TaxRateController;
use App\Http\Controllers\App\UnitController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\BusinessLoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PricingController;
use App\Http\Controllers\Public\RegistrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public — the marketing website (#106–#109)
|--------------------------------------------------------------------------
| No middleware beyond the `web` group: these pages are for people who do not
| have an account yet, which is the entire point of them.
|
| ⚠️ They still sit behind ApplyPlatformSettings and EnforceMaintenanceMode, so
| the operator's branding reaches them and a closed platform does not sell
| itself to somebody who cannot then sign in (#110, #160).
*/
Route::get('/', [PageController::class, 'home'])->name('home');

Route::get('pricing', [PricingController::class, 'index'])->name('pricing');
Route::get('faq', [PageController::class, 'faq'])->name('faq');
Route::get('contact', [PageController::class, 'contact'])->name('contact');

/*
| Features, POS, Inventory, Reports — one template, four sets of words held in
| MarketingContent. The `where` keeps the route from swallowing every unmatched
| path and turning a typo into a 500 instead of a 404.
*/
Route::get('{page}', [PageController::class, 'page'])
    ->whereIn('page', ['features', 'pos', 'inventory', 'reports'])
    ->name('page');

/*
| Sign-up (#109). `guest:web` because somebody already signed in has a shop —
| sending them here would offer to create a second one under their own login.
| The registration switch itself is checked in the controller AND again in the
| service, since a form that was open on load can be closed on submit.
*/
Route::middleware('guest:web')->group(function () {
    Route::get('register', [RegistrationController::class, 'create'])->name('register');
    // Throttled per IP and per HOUR (#100): this one endpoint creates a
    // business, a user and a subscription without anybody signing in, and a
    // limit that resets in sixty seconds would not slow a script down at all.
    Route::post('register', [RegistrationController::class, 'store'])
        ->middleware('throttle:register')->name('register.store');
});

/*
|--------------------------------------------------------------------------
| Business auth + panel — /app  (web guard, tenant-scoped)
|--------------------------------------------------------------------------
*/
Route::middleware('guest:web')->group(function () {
    Route::get('login', [BusinessLoginController::class, 'create'])->name('login');
    Route::post('login', [BusinessLoginController::class, 'store'])->name('login.store');

    // Password reset (#63). Route names match Laravel's ResetPassword
    // notification expectations (`password.reset` builds the emailed link).
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::post('logout', [BusinessLoginController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');

// Leaving an impersonated session (#178). Deliberately outside the tenant and
// subscription gates: the whole point is that it works from a session those
// gates might otherwise reject, so the operator is never stranded.
Route::post('stop-impersonating', [ImpersonationController::class, 'stop'])
    ->middleware('auth:web')
    ->name('impersonation.stop');

// Everything under /app runs the `tenant.app` stack (auth:web → tenant →
// subscription), declared in bootstrap/app.php. Bundling the subscription gate
// into the group means a new route cannot silently skip the paywall. #187
Route::middleware('tenant.app')
    ->prefix('app')
    ->name('app.')
    ->group(function () {
        Route::get('dashboard', [AppDashboardController::class, 'index'])->name('dashboard');

        // Billing stays reachable when everything else is locked (#11, #78, #84)
        // — see CheckSubscription::$alwaysAllowed.
        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        Route::get('billing/plans', [BillingController::class, 'plans'])->name('billing.plans');

        /*
        |--------------------------------------------------------------------
        | Organisation — branches, counters, roles, staff (Phase 3)
        |--------------------------------------------------------------------
        | Every route carries its own `permission:` gate rather than relying on
        | a hidden menu (#188). The middleware runs the full three-layer check
        | (#187), so a permission whose feature is not in the plan is refused
        | here too — no route needs both `feature:` and `permission:`.
        |
        | Reads and writes are split deliberately: seeing the branch list is not
        | the same authority as changing it.
        */

        // ---- branches (#47) ------------------------------------------------
        Route::get('branches', [BranchController::class, 'index'])
            ->middleware('permission:branches.view')->name('branches.index');

        Route::middleware('permission:branches.manage')->group(function () {
            Route::get('branches/create', [BranchController::class, 'create'])->name('branches.create');
            Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
            Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
            Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
            Route::post('branches/{branch}/toggle', [BranchController::class, 'toggle'])->name('branches.toggle');
            Route::post('branches/{branch}/main', [BranchController::class, 'makeMain'])->name('branches.main');
            Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
        });

        // ---- POS counters (#49) --------------------------------------------
        Route::middleware('permission:pos_counters.manage')->group(function () {
            Route::get('counters', [PosCounterController::class, 'index'])->name('counters.index');
            Route::get('counters/create', [PosCounterController::class, 'create'])->name('counters.create');
            Route::post('counters', [PosCounterController::class, 'store'])->name('counters.store');
            Route::get('counters/{counter}/edit', [PosCounterController::class, 'edit'])->name('counters.edit');
            Route::put('counters/{counter}', [PosCounterController::class, 'update'])->name('counters.update');
            Route::post('counters/{counter}/toggle', [PosCounterController::class, 'toggle'])->name('counters.toggle');
            Route::delete('counters/{counter}', [PosCounterController::class, 'destroy'])->name('counters.destroy');
        });

        // ---- roles & permissions (#51) -------------------------------------
        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        /*
        |--------------------------------------------------------------------
        | Catalogue — products and the lists they are filed under (Phase 4)
        |--------------------------------------------------------------------
        | Products split their gates by VERB (#188): seeing the catalogue,
        | adding to it, editing it and archiving from it are four different
        | authorities, and a shop floor role usually has only the first.
        |
        | Categories, brands and units share one `catalog.manage` gate — they
        | are the same job, and splitting them would only make role setup
        | tedious without making anything safer.
        */

        // ---- products (#24, #25, #105) --------------------------------------
        Route::get('products', [ProductController::class, 'index'])
            ->middleware('permission:products.view')->name('products.index');

        // Barcode labels (#27). Plan-gated on the barcode capability, and
        // reachable by anyone who may look at the catalogue — pricing up a
        // delivery is shop-floor work, not an admin task.
        Route::middleware(['feature:pos.barcode_scanner', 'permission:products.view'])->group(function () {
            Route::get('products/labels', [BarcodeLabelController::class, 'index'])->name('products.labels');
            Route::post('products/labels', [BarcodeLabelController::class, 'sheet'])->name('products.labels.sheet');
        });

        Route::middleware('permission:products.create')->group(function () {
            Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
        });

        Route::middleware('permission:products.update')->group(function () {
            Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::post('products/{product}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
        });

        Route::delete('products/{product}', [ProductController::class, 'destroy'])
            ->middleware('permission:products.delete')->name('products.destroy');

        /*
        | Import & export (#150, #151).
        |
        | Two different authorities, because they are two different risks:
        | importing WRITES the catalogue (and is a plan feature), while exporting
        | takes data out of the building — which is why #52 marks it sensitive
        | and it rides on `reports.export`.
        */
        Route::get('products-transfer', [ProductImportController::class, 'index'])
            ->middleware('permission:products.import')->name('products.import');

        Route::middleware(['feature:catalog.import', 'permission:products.import'])->group(function () {
            Route::get('products-transfer/template', [ProductImportController::class, 'template'])->name('products.import.template');
            Route::post('products-transfer', [ProductImportController::class, 'store'])->name('products.import.store');
        });

        Route::get('products-transfer/export', [ProductImportController::class, 'export'])
            ->middleware(['permission:reports.export', 'throttle:export'])->name('products.export');

        // ---- inventory (#28, #30, #31, #136) --------------------------------
        // Reading stock and changing it are separate authorities: a shop floor
        // role usually needs the first and must not have the second.
        Route::middleware('permission:inventory.view')->group(function () {
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
            Route::get('inventory/{product}/ledger', [InventoryController::class, 'ledger'])->name('inventory.ledger');

            // Batches & expiry (#34). Behind the expiry feature, because a plan
            // without it has no batches to report on.
            Route::get('inventory-expiry', [InventoryController::class, 'expiry'])
                ->middleware('feature:inventory.expiry_tracking')
                ->name('inventory.expiry');
        });

        Route::post('inventory/adjust', [InventoryController::class, 'adjust'])
            ->middleware('permission:inventory.adjust')->name('inventory.adjust');

        // ---- stock transfers (#32) ------------------------------------------
        // One permission covers the whole workflow; WHICH end of a transfer a
        // person may act on is decided per action by the service, because
        // sending is the source branch's job and receiving is the destination's.
        Route::middleware(['feature:inventory.transfers', 'permission:inventory.transfer'])
            ->group(function () {
                Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
                Route::get('transfers/create', [StockTransferController::class, 'create'])->name('transfers.create');
                Route::post('transfers', [StockTransferController::class, 'store'])->name('transfers.store');
                Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
                Route::get('transfers/{transfer}/edit', [StockTransferController::class, 'edit'])->name('transfers.edit');
                Route::put('transfers/{transfer}', [StockTransferController::class, 'update'])->name('transfers.update');
                Route::post('transfers/{transfer}/send', [StockTransferController::class, 'send'])->name('transfers.send');
                Route::post('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
                Route::post('transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
                Route::delete('transfers/{transfer}', [StockTransferController::class, 'destroy'])->name('transfers.destroy');
            });

        // ---- categories, brands, units (#26) --------------------------------
        Route::middleware('permission:catalog.manage')->group(function () {
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create');
            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

            Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
            Route::get('brands/create', [BrandController::class, 'create'])->name('brands.create');
            Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
            Route::get('brands/{brand}/edit', [BrandController::class, 'edit'])->name('brands.edit');
            Route::put('brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
            Route::delete('brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

            Route::get('units', [UnitController::class, 'index'])->name('units.index');
            Route::get('units/create', [UnitController::class, 'create'])->name('units.create');
            Route::post('units', [UnitController::class, 'store'])->name('units.store');
            Route::get('units/{unit}/edit', [UnitController::class, 'edit'])->name('units.edit');
            Route::put('units/{unit}', [UnitController::class, 'update'])->name('units.update');
            Route::delete('units/{unit}', [UnitController::class, 'destroy'])->name('units.destroy');
        });

        /*
        |--------------------------------------------------------------------
        | Customers & suppliers (Phase 5)
        |--------------------------------------------------------------------
        | Three authorities, not one, and the split is deliberate (#52):
        |
        |   *.view    reading the list and the profile
        |   *.manage  editing who they are
        |   *.ledger  moving what they owe — the sensitive one
        |
        | A shop assistant who may look a customer up should not thereby be able
        | to write off their debt.
        */

        /*
        |--------------------------------------------------------------------
        | Sales, after the fact (Phase 7)
        |--------------------------------------------------------------------
        | `sales.view` lets someone find their OWN sales; `sales.view_all` shows
        | everyone's. The narrower one is what a cashier needs — the receipt they
        | printed five minutes ago — and it is enforced in the query, not by
        | hiding rows on a page that already fetched them.
        |
        | The receipt is deliberately reachable with only `sales.view`: reprinting
        | for the customer standing at the counter is the same job as selling.
        */
        Route::middleware('feature:sales.invoicing')->group(function () {
            Route::middleware('permission:sales.view')->group(function () {
                Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
                Route::get('sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
                Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
            });

            Route::post('sales/{sale}/void', [SaleController::class, 'void'])
                ->middleware('permission:sales.void')->name('sales.void');

            /*
            | Returns (#53) carry their own feature AND their own permission
            | (#140): handing money back out of the till is not the same
            | authority as taking it in, and plenty of shops let anyone sell
            | while only a supervisor may refund.
            */
            Route::middleware(['feature:sales.returns', 'permission:sales.return'])->group(function () {
                Route::get('returns', [SaleReturnController::class, 'index'])->name('returns.index');
                Route::get('sales/{sale}/returns/create', [SaleReturnController::class, 'create'])->name('returns.create');
                Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store'])->name('returns.store');
                Route::get('returns/{saleReturn}', [SaleReturnController::class, 'show'])->name('returns.show');
            });
        });

        /*
        |--------------------------------------------------------------------
        | The till (Phase 7)
        |--------------------------------------------------------------------
        | The screen and its JSON endpoints share one permission — `pos.operate`
        | — because they are one activity: a cashier who may sell must be able
        | to search, scan and hold, and one who may not should not be able to
        | reach any of it.
        |
        | Two exceptions carry their own authority: adding a customer from the
        | till is `customers.manage` (checked in the controller so the till can
        | hide the button), and opening the drawer is the cash-register feature.
        */
        Route::middleware(['feature:pos.terminal', 'permission:pos.operate'])
            ->prefix('pos')
            ->name('pos.')
            ->group(function () {
                Route::get('/', [PosController::class, 'index'])->name('index');

                // JSON, called constantly, never reloading the page (#90).
                Route::middleware('throttle:search')->group(function () {
                    Route::get('search', [PosController::class, 'search'])->name('search');
                    Route::get('scan', [PosController::class, 'scan'])->name('scan');
                });

                // Deliberately loose. A till that refuses a sale is worse than
                // almost anything this limit prevents; the real defence against
                // a double submit is the per-cart idempotency key and its unique
                // index. This is only a ceiling on something pathological.
                Route::post('checkout', [PosController::class, 'checkout'])
                    ->middleware('throttle:sale')->name('checkout');

                Route::post('hold', [PosController::class, 'hold'])->name('hold');
                Route::get('holds/{sale}', [PosController::class, 'resumeHold'])->name('holds.resume');
                Route::delete('holds/{sale}', [PosController::class, 'discardHold'])->name('holds.discard');

                Route::post('customers', [PosController::class, 'quickCustomer'])->name('customers.store');
                Route::post('favourites/{product}', [PosController::class, 'toggleFavourite'])->name('favourites.toggle');

                Route::post('session', [PosController::class, 'openSession'])
                    ->middleware('feature:accounting.cash_register')->name('session.open');
            });

        /*
        |--------------------------------------------------------------------
        | Purchases (Phase 6)
        |--------------------------------------------------------------------
        | The gates follow what each action actually does, not what screen it
        | happens to live on:
        |
        |   purchases.view    read the orders
        |   purchases.create  raise one, send it, and take the delivery in
        |   purchases.update  edit a draft
        |   purchases.void    call one off, or delete an untouched draft
        |   purchases.return  send goods back (#37)
        |   suppliers.ledger  PAY the bill — money on a supplier account is the
        |                     same authority whether it is paid from the account
        |                     screen or from the purchase (#52)
        */
        Route::middleware('feature:purchases.orders')->group(function () {
            Route::get('purchases', [PurchaseController::class, 'index'])
                ->middleware('permission:purchases.view')->name('purchases.index');

            Route::middleware('permission:purchases.create')->group(function () {
                Route::get('purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
                Route::post('purchases', [PurchaseController::class, 'store'])->name('purchases.store');
                Route::post('purchases/{purchase}/order', [PurchaseController::class, 'order'])->name('purchases.order');
                Route::post('purchases/{purchase}/receive', [PurchaseController::class, 'receive'])->name('purchases.receive');
            });

            Route::middleware('permission:purchases.update')->group(function () {
                Route::get('purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->name('purchases.edit');
                Route::put('purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
            });

            Route::middleware('permission:purchases.void')->group(function () {
                Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
                Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');
            });

            Route::post('purchases/{purchase}/payments', [PurchaseController::class, 'settle'])
                ->middleware('permission:suppliers.ledger')->name('purchases.payments');

            // Returns (#37) — their own feature and their own permission.
            Route::middleware(['feature:purchases.returns', 'permission:purchases.return'])->group(function () {
                Route::get('purchases/{purchase}/returns/create', [PurchaseReturnController::class, 'create'])->name('purchases.returns.create');
                Route::post('purchases/{purchase}/returns', [PurchaseReturnController::class, 'store'])->name('purchases.returns.store');
            });

            Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])
                ->middleware('permission:purchases.view')->name('purchases.show');
        });

        // ---- customers (#39, #40, #41, #105) --------------------------------
        Route::middleware('feature:customers.management')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])
                ->middleware('permission:customers.view')->name('customers.index');

            Route::middleware('permission:customers.manage')->group(function () {
                Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
                Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
                Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
                Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
                Route::post('customers/{customer}/toggle', [CustomerController::class, 'toggle'])->name('customers.toggle');
                Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
            });

            Route::get('customers/{customer}', [CustomerController::class, 'show'])
                ->middleware('permission:customers.view')->name('customers.show');

            // The ledger sits behind its own feature AND its own permission: a
            // plan without customer accounts has no statements to move money on.
            Route::middleware(['feature:accounting.customer_ledger', 'permission:customers.ledger'])->group(function () {
                Route::post('customers/{customer}/payments', [CustomerLedgerController::class, 'payment'])->name('customers.payments');
                Route::post('customers/{customer}/adjustments', [CustomerLedgerController::class, 'adjustment'])->name('customers.adjustments');
            });
        });

        // ---- suppliers (#38, #42) -------------------------------------------
        Route::middleware('feature:purchases.supplier_ledger')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])
                ->middleware('permission:suppliers.view')->name('suppliers.index');

            Route::middleware('permission:suppliers.manage')->group(function () {
                Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
                Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
                Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
                Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
                Route::post('suppliers/{supplier}/toggle', [SupplierController::class, 'toggle'])->name('suppliers.toggle');
                Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
            });

            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
                ->middleware('permission:suppliers.view')->name('suppliers.show');

            Route::middleware('permission:suppliers.ledger')->group(function () {
                Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'payment'])->name('suppliers.payments');
                Route::post('suppliers/{supplier}/adjustments', [SupplierController::class, 'adjustment'])->name('suppliers.adjustments');
            });
        });

        /*
        | ---- global search (#75) ---------------------------------------------
        | No feature or permission gate on the ROUTE: what it may return is
        | decided per source inside the service, against the same permission
        | that guards each module's own screen. A route-level gate would have to
        | be the strictest of five and would silence the box for most people.
        */
        // Fires on every keystroke, so the ceiling sits well above a fast
        // typist — it is here to stop a runaway loop, not a person.
        Route::get('search', SearchController::class)
            ->middleware('throttle:search')->name('search');

        // ---- expenses & other income (#43, #44) -----------------------------
        Route::middleware('feature:accounting.expenses')->group(function () {
            Route::get('expenses', [ExpenseController::class, 'index'])
                ->middleware('permission:expenses.view')->name('expenses.index');

            Route::middleware('permission:expenses.manage')->group(function () {
                Route::get('expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
                Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
                Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
                Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
                Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

                /*
                | Categories are the shop's own filing (#43, #190), so managing
                | them rides on the same authority as recording an expense —
                | somebody who may book the rent may also decide it is filed
                | under "Rent" and not "Miscellaneous".
                */
                Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
                Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
                Route::put('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
                Route::delete('expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
            });

            Route::get('income', [OtherIncomeController::class, 'index'])
                ->middleware('permission:expenses.view')->name('income.index');

            Route::middleware('permission:expenses.manage')->group(function () {
                Route::get('income/create', [OtherIncomeController::class, 'create'])->name('income.create');
                Route::post('income', [OtherIncomeController::class, 'store'])->name('income.store');
                Route::get('income/{income}/edit', [OtherIncomeController::class, 'edit'])->name('income.edit');
                Route::put('income/{income}', [OtherIncomeController::class, 'update'])->name('income.update');
                Route::delete('income/{income}', [OtherIncomeController::class, 'destroy'])->name('income.destroy');
            });
        });

        /*
        | ---- profit & loss (#45) --------------------------------------------
        | Two gates, and they are not the same question. `accounting.profit_loss`
        | asks whether the plan includes it; `reports.view_profit` asks whether
        | this person may see what the shop earns. A cashier on the best plan in
        | the catalogue still may not.
        */
        Route::get('reports/profit-loss', [ProfitLossController::class, 'index'])
            ->middleware(['feature:accounting.profit_loss', 'permission:reports.view_profit'])
            ->name('reports.profit-loss');

        /*
        | ---- the reports module (#54, #55, #56) ------------------------------
        | ⚠️ Registered AFTER `reports/profit-loss` on purpose: `{report}`
        | matches anything without a slash, so the literal route has to be
        | declared first or the P&L would resolve as a report key.
        |
        | The gates here are the WEAKEST any report needs — the section itself.
        | Each report carries its own feature and permission and both are
        | checked per report in the controller (#187), because a route-level
        | gate would have to be the strictest of thirty and nobody would see
        | anything.
        */
        Route::middleware(['feature:reports.basic', 'permission:reports.view'])->group(function () {
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');

            // Taking figures OUT of the system is its own authority: an export
            // leaves with the person who made it and outlives their account.
            Route::get('reports/{report}/export/{format}', [ReportController::class, 'export'])
                ->middleware(['permission:reports.export', 'throttle:export'])
                ->name('reports.export');
        });

        /*
        | ---- the bell (#76, #77) ---------------------------------------------
        | No feature gate and no permission: every employee should be told the
        | platform is going down on Sunday. What the bell CONTAINS is filtered
        | per person inside the service — a cashier who cannot open the
        | inventory is not told what is low in it.
        */
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/{announcement}/dismiss', [NotificationController::class, 'dismiss'])
            ->name('notifications.dismiss');

        /*
        | ---- settings (#57–#60, #153–#157) -----------------------------------
        | One permission for the lot. Splitting "may change the receipt" from
        | "may change the discount ceiling" would invent a dozen permissions
        | that every shop would grant together anyway — and `settings.manage` is
        | already marked sensitive, which is the honest signal.
        */
        Route::middleware('permission:settings.manage')->group(function () {
            Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
            Route::get('settings/{group}', [SettingsController::class, 'show'])->name('settings.show');

            Route::put('settings/business', [SettingsController::class, 'updateBusiness'])->name('settings.business');
            Route::put('settings/payment-qr', [SettingsController::class, 'updatePaymentQr'])->name('settings.payment-qr');
            Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/{group}/reset', [SettingsController::class, 'reset'])->name('settings.reset');

            // Tax rates are a list rather than a knob, so they get their own
            // routes under the same permission (#59).
            Route::middleware('feature:sales.tax')->group(function () {
                Route::post('tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
                Route::put('tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
                Route::delete('tax-rates/{taxRate}', [TaxRateController::class, 'destroy'])->name('tax-rates.destroy');
            });
        });

        // ---- employees (#50) -----------------------------------------------
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('permission:employees.view')->name('employees.index');

        Route::middleware('permission:employees.manage')->group(function () {
            Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
            Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
            Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
            Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
            Route::post('employees/{employee}/toggle', [EmployeeController::class, 'toggle'])->name('employees.toggle');
            Route::post('employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.reset-password');
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
        });
    });

/*
|--------------------------------------------------------------------------
| Super-admin auth + panel — /admin  (admin guard, NOT tenant-scoped)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminLoginController::class, 'create'])->name('login');
        Route::post('login', [AdminLoginController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');
        Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/', fn () => redirect()->route('admin.dashboard'))->name('home');

        // ---------------------------------------------------------- plans (#7)
        Route::get('plans/matrix', [PlanController::class, 'matrix'])->name('plans.matrix');
        Route::post('plans/{plan}/toggle', [PlanController::class, 'toggle'])->name('plans.toggle');
        Route::resource('plans', PlanController::class)->except(['show']);

        // ----------------------------------------------------- businesses (#6)
        Route::prefix('businesses/{business}')->name('businesses.')->group(function () {
            Route::post('suspend', [BusinessController::class, 'suspend'])->name('suspend');
            Route::post('activate', [BusinessController::class, 'activate'])->name('activate');
            Route::post('reset-password', [BusinessController::class, 'resetUserPassword'])->name('reset-password');
            Route::post('impersonate', [ImpersonationController::class, 'start'])->name('impersonate');

            // Subscription actions — all delegate to SubscriptionService (#82, #83).
            Route::prefix('subscription')->name('subscription.')->group(function () {
                Route::post('/', [BusinessSubscriptionController::class, 'store'])->name('store');
                Route::post('renew', [BusinessSubscriptionController::class, 'renew'])->name('renew');
                Route::post('extend', [BusinessSubscriptionController::class, 'extend'])->name('extend');
                Route::post('trial-days', [BusinessSubscriptionController::class, 'addTrialDays'])->name('trial-days');
                Route::post('cancel', [BusinessSubscriptionController::class, 'cancel'])->name('cancel');
                Route::post('resume', [BusinessSubscriptionController::class, 'resume'])->name('resume');
                Route::post('payments', [BusinessSubscriptionController::class, 'recordPayment'])->name('payments');
            });

            // Per-business feature/quota overrides (#10).
            Route::prefix('overrides')->name('overrides.')->group(function () {
                Route::get('/', [BusinessOverrideController::class, 'index'])->name('index');
                Route::post('features', [BusinessOverrideController::class, 'storeFeature'])->name('features.store');
                Route::delete('features/{feature}', [BusinessOverrideController::class, 'destroyFeature'])->name('features.destroy');
                Route::post('limits', [BusinessOverrideController::class, 'storeLimit'])->name('limits.store');
                Route::delete('limits/{limit}', [BusinessOverrideController::class, 'destroyLimit'])->name('limits.destroy');
            });

            // Internal support notes (#159).
            Route::prefix('notes')->name('notes.')->group(function () {
                Route::post('/', [BusinessNoteController::class, 'store'])->name('store');
                Route::put('{note}', [BusinessNoteController::class, 'update'])->name('update');
                Route::post('{note}/pin', [BusinessNoteController::class, 'pin'])->name('pin');
                Route::delete('{note}', [BusinessNoteController::class, 'destroy'])->name('destroy');
            });
        });

        Route::resource('businesses', BusinessController::class);

        // ------------------------------------------- subscriptions overview (#82)
        Route::get('subscriptions', [BusinessSubscriptionController::class, 'index'])->name('subscriptions.index');

        // ---------------------------------------------- system alerts (#179)
        // ---------------------------------------- announcements (#77)
        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

        /*
        | -------------------------------- platform settings (#110, #111, #160)
        | ⚠️ These routes must stay reachable during maintenance mode — they
        | are how it gets turned off. EnforceMaintenanceMode lets every
        | /admin path through for exactly that reason.
        */
        Route::get('settings', [PlatformSettingsController::class, 'index'])->name('settings.index');
        Route::get('settings/{group}', [PlatformSettingsController::class, 'show'])->name('settings.show');
        Route::put('settings/logo', [PlatformSettingsController::class, 'updateLogo'])->name('settings.logo');
        Route::put('settings/{group}', [PlatformSettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/{group}/reset', [PlatformSettingsController::class, 'reset'])->name('settings.reset');

        Route::get('notifications', [SystemNotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/reconcile', [SystemNotificationController::class, 'reconcile'])->name('notifications.reconcile');
    });
});

