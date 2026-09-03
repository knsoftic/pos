<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlanRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\PlanRequest;
use App\Services\PlanRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shops asking to change plan (#82).
 *
 * ⚠️ `allTenants()` ON EVERY QUERY, and it is not optional. PlanRequest carries
 * the tenant trait — rightly, since a shop is entitled to see its own asks — and
 * that global scope filters by whatever business is in context. In the operator
 * panel there is no business in context, so without this the list would come
 * back empty and read as "nobody has asked for anything", which is the single
 * most misleading thing this screen could say.
 *
 * Marking a request done does NOT move the shop. That is a separate, deliberate
 * act on the subscription screen, by somebody who has seen the money.
 */
class PlanRequestController extends Controller
{
    public function __construct(protected PlanRequestService $requests) {}

    public function index(Request $request): View
    {
        $status = PlanRequestStatus::tryFrom((string) $request->query('status', 'pending'));

        return view('admin.plan-requests.index', [
            'status' => $status,
            'requests' => PlanRequest::query()
                ->allTenants()
                ->with(['business', 'plan', 'user', 'handler'])
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->latestFirst()
                ->paginate(25)
                ->withQueryString(),
            'pendingCount' => PlanRequest::query()->allTenants()->pending()->count(),
        ]);
    }

    public function update(Request $request, PlanRequest $planRequest): RedirectResponse
    {
        $status = PlanRequestStatus::tryFrom((string) $request->input('status'));

        abort_if($status === null, 422, 'Unknown status.');

        $this->requests->settle($planRequest, $status, auth('admin')->user());

        return back()->with('success', 'Request marked '.strtolower($status->label()).'.');
    }
}
