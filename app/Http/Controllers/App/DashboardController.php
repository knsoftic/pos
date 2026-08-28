<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Business panel dashboard (/app).
 *
 * Every query here is automatically tenant-scoped by the active TenantContext
 * (bound by SetBusinessTenant) — note there is no `where business_id` in sight,
 * and there must never be one written by hand. #3 / #131
 *
 * A card only appears once the module behind it is real. A dashboard full of
 * em-dashes teaches people to ignore it, so figures are added here as their
 * phase lands rather than being stubbed in advance — and each one is gated by
 * the same permission that guards its module, so a cashier's dashboard does not
 * quietly leak counts they cannot open (#52, #188).
 */
class DashboardController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    public function index(Request $request, TenantContext $tenant): View
    {
        $business = $tenant->business();
        $user = Auth::guard('web')->user();

        // Auto-scoped: returns only THIS business's users. The role is eager
        // loaded because the table names it for every row (#167).
        $team = User::query()
            ->with('role:id,name')
            ->orderByDesc('is_business_owner')
            ->orderBy('name')
            ->take(10)
            ->get();

        $stats = [
            'staff_count' => User::count(),
            'owner_count' => User::where('is_business_owner', true)->count(),
        ];

        // Catalogue (Phase 4). Null means "not yours to see", which the view
        // renders as a locked card rather than a zero.
        $stats['products_count'] = $user->can(PermissionRegistry::PRODUCTS_VIEW)
            ? Product::query()->count()
            : null;

        $stats['products_inactive'] = $user->can(PermissionRegistry::PRODUCTS_VIEW)
            ? Product::query()->where('is_active', false)->count()
            : null;

        // Inventory (Phase 4). Branch-scoped like everything else, so a cashier
        // sees their own shop's shortages, not the whole company's.
        if ($user->can(PermissionRegistry::INVENTORY_VIEW) && $this->inventory->isTrackingEnabled()) {
            $stats['low_stock_count'] = $this->inventory->lowStock()->count();
            $stats['stock_value'] = $user->can(PermissionRegistry::PRODUCTS_VIEW_COST)
                ? $this->inventory->valuation()['value']
                : null;
        } else {
            $stats['low_stock_count'] = null;
            $stats['stock_value'] = null;
        }

        return view('app.dashboard', [
            'business' => $business,
            'user' => $user,
            'team' => $team,
            'stats' => $stats,
        ]);
    }
}
