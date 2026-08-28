<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\BranchRequest;
use App\Models\Branch;
use App\Services\BranchService;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Branch management (#47). Thin: every rule that decides whether a branch may be
 * created, renamed, closed or removed lives in {@see BranchService}.
 *
 * The index deliberately shows the branch quota meter and, when the plan is
 * single-branch, says so plainly instead of offering a button that would throw.
 */
class BranchController extends Controller
{
    public function __construct(
        protected BranchService $branches,
        protected FeatureService $features,
        protected PlanLimitService $limits,
    ) {}

    public function index(): View
    {
        return view('app.branches.index', [
            'branches' => Branch::query()
                ->ordered()
                ->withCount(['counters', 'users'])
                ->get(),
            'meter' => $this->limits->meter(LimitRegistry::BRANCHES),
            'multiBranch' => $this->features->enabled(FeatureRegistry::BRANCHES_MULTI_BRANCH),
        ]);
    }

    public function create(): View
    {
        return view('app.branches.create', ['branch' => new Branch]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = $this->branches->create($request->validated());

        return redirect()
            ->route('app.branches.index')
            ->with('success', "\"{$branch->name}\" added.");
    }

    public function edit(Branch $branch): View
    {
        return view('app.branches.edit', ['branch' => $branch]);
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->branches->update($branch, $request->validated());

        return redirect()
            ->route('app.branches.index')
            ->with('success', "\"{$branch->name}\" updated.");
    }

    public function makeMain(Branch $branch): RedirectResponse
    {
        $this->branches->makeMain($branch);

        return back()->with('success', "\"{$branch->name}\" is now the main branch.");
    }

    public function toggle(Branch $branch): RedirectResponse
    {
        if ($branch->is_main && $branch->is_active) {
            return back()->with('error', 'The main branch cannot be closed. Make another branch the main one first.');
        }

        $this->branches->setActive($branch, ! $branch->is_active);

        return back()->with('success', "\"{$branch->name}\" ".($branch->is_active ? 'reopened' : 'closed').'.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if (! $this->branches->delete($branch)) {
            return back()->with('error', $branch->is_main
                ? 'The main branch cannot be deleted.'
                : 'That branch still has staff or counters. Move them first, or just close the branch.');
        }

        return redirect()
            ->route('app.branches.index')
            ->with('success', 'Branch deleted.');
    }
}
