<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\ProfitService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Profit & Loss statement (#45).
 *
 * Behind `reports.view_profit` (#52) as well as the plan feature: a P&L is the
 * most sensitive screen in the system — it tells anyone who opens it what the
 * shop is actually worth to its owner, and what every product really costs.
 */
class ProfitLossController extends Controller
{
    public function __construct(protected ProfitService $profit) {}

    public function index(Request $request): View
    {
        $filters = [
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'branch_id' => $request->query('branch_id') ?: null,
        ];

        $statement = $this->profit->statement($filters);

        return view('app.reports.profit-loss', [
            'statement' => $statement,
            'filters' => $filters,
            // The chart reads the same arithmetic as the statement, so a spike
            // in one is always visible in the other.
            'daily' => $this->profit->daily(
                ['from' => $statement['from'], 'to' => $statement['to'], 'days' => $statement['days']],
                $filters['branch_id'] !== null ? (int) $filters['branch_id'] : null,
            ),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
