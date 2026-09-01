<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Business panel dashboard (/app) — #12, #123, #124.
 *
 * Every query behind this is automatically tenant-scoped by the active
 * TenantContext (bound by SetBusinessTenant) — note there is no
 * `where business_id` in sight, and there must never be one written by hand
 * (#3, #131).
 *
 * The figures come from {@see DashboardService}, which reads the same
 * definitions the reports do. A dashboard that disagreed with the report it
 * links to would leave the owner with two numbers and no way to choose.
 */
class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard) {}

    public function index(Request $request, TenantContext $tenant): View
    {
        $user = Auth::guard('web')->user();

        return view('app.dashboard', [
            'business' => $tenant->business(),
            'user' => $user,
            'data' => $this->dashboard->build($request->query()),

            // The team table is the one thing here that is about the business
            // rather than about trading, so it stays a plain query.
            'team' => User::query()
                ->with('role:id,name')
                ->orderByDesc('is_business_owner')
                ->orderBy('name')
                ->take(6)
                ->get(),
        ]);
    }
}
