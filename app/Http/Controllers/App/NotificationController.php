<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\TenantNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The bell in a shop's workspace (#76, #77).
 *
 * Only announcements can be dismissed. An ALERT is a condition and disappears
 * when the shop fixes it — see {@see TenantNotificationService} for why letting
 * somebody silence a true alert would teach them to silence all of them.
 */
class NotificationController extends Controller
{
    public function __construct(protected TenantNotificationService $notifications) {}

    public function index(): View
    {
        return view('app.notifications.index', [
            'items' => $this->notifications->all(),
        ]);
    }

    public function dismiss(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->notifications->dismiss($announcement, $request->user());

        return back()->with('success', 'Notice dismissed.');
    }
}
