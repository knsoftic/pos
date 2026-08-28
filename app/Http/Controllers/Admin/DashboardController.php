<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Super-admin dashboard (/admin). NOT tenant-scoped — aggregates across
 * every business in the system.
 */
class DashboardController extends Controller
{
    /** How many months of history the growth chart shows. */
    protected const GROWTH_MONTHS = 6;

    public function index(): View
    {
        $stats = [
            'businesses_total' => Business::count(),
            'businesses_active' => Business::where('status', Business::STATUS_ACTIVE)->count(),
            'businesses_suspended' => Business::where('status', Business::STATUS_SUSPENDED)->count(),
            // allTenants() defensively strips any tenant scope (there is none on
            // the admin routes, but be explicit — this must span all tenants).
            'users_total' => User::allTenants()->count(),
        ];

        $recentBusinesses = Business::latest()->take(8)->get();

        return view('admin.dashboard', [
            'stats' => $stats,
            'recentBusinesses' => $recentBusinesses,
            'growth' => $this->growthChart(),
        ]);
    }

    /**
     * New businesses signed up per month, oldest → newest.
     *
     * @return array{labels: list<string>, series: list<int>}
     */
    protected function growthChart(): array
    {
        $labels = [];
        $series = [];

        foreach (range(self::GROWTH_MONTHS - 1, 0) as $monthsAgo) {
            $start = Carbon::now()->startOfMonth()->subMonths($monthsAgo);

            $labels[] = $start->format('M Y');
            $series[] = Business::whereBetween('created_at', [
                $start,
                $start->copy()->endOfMonth(),
            ])->count();
        }

        return ['labels' => $labels, 'series' => $series];
    }
}
