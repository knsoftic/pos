<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Business panel dashboard (/app).
 *
 * Every query here is automatically tenant-scoped by the active TenantContext
 * (bound by SetBusinessTenant) — note there is no `where business_id` in sight,
 * and there must never be one written by hand. #3 / #131
 */
class DashboardController extends Controller
{
    public function index(TenantContext $tenant): View
    {
        $business = $tenant->business();

        // Auto-scoped: returns only THIS business's users.
        $team = User::query()
            ->orderByDesc('is_business_owner')
            ->orderBy('name')
            ->take(10)
            ->get();

        // Real KPIs (sales, profit, stock…) arrive with their modules in later
        // phases; for now surface the tenant-scoped data that actually exists.
        $stats = [
            'staff_count' => User::count(),
            'owner_count' => User::where('is_business_owner', true)->count(),
        ];

        return view('app.dashboard', [
            'business' => $business,
            'user' => Auth::guard('web')->user(),
            'team' => $team,
            'stats' => $stats,
        ]);
    }
}
