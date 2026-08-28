<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use App\Services\SystemNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The operator's alert feed (#179). NOT tenant-scoped — it reports on every
 * business in the system.
 *
 * The list itself lives in SystemNotificationService; this controller only
 * renders it and offers the one repair that can be done from a browser
 * (reconciling stale subscription status columns), so the operator does not
 * need shell access to clear that alert.
 */
class SystemNotificationController extends Controller
{
    public function __construct(
        protected SystemNotificationService $notifications,
        protected SubscriptionService $subscriptions,
    ) {}

    public function index(): View
    {
        return view('admin.notifications.index', [
            'alerts' => $this->notifications->alerts(),
            'affected' => $this->notifications->affectedCount(),
        ]);
    }

    /**
     * Rewrite stale `subscriptions.status` columns from the derived state.
     *
     * Access never depended on the column (#79), so this changes no
     * entitlements — it only makes the stored value agree with the dates that
     * already govern access, which is what reports and list filters read.
     */
    public function reconcile(): RedirectResponse
    {
        $changed = $this->subscriptions->reconcileStatuses();

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', $changed === 0
                ? 'Every subscription status was already up to date.'
                : trans_choice(':count subscription status brought back in line.|:count subscription statuses brought back in line.', $changed, ['count' => $changed]));
    }
}
