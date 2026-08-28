<?php

use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessNoteController;
use App\Http\Controllers\Admin\BusinessOverrideController;
use App\Http\Controllers\Admin\BusinessSubscriptionController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SystemNotificationController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\DashboardController as AppDashboardController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\BusinessLoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| The marketing website (landing, pricing, trial registration) is built in
| Phase 14. For now the root simply routes visitors to the business login.
*/
Route::get('/', fn () => redirect()->route('login'))->name('home');

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
        Route::get('notifications', [SystemNotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/reconcile', [SystemNotificationController::class, 'reconcile'])->name('notifications.reconcile');
    });
});
