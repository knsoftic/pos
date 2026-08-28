<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\PosCounterRequest;
use App\Models\Branch;
use App\Models\PosCounter;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Services\PosCounterService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * POS counters, per branch (#49).
 *
 * The listing is filtered twice without this controller doing anything: the
 * tenant scope keeps it inside the business, and the branch scope keeps it
 * inside the branches this user may reach (#48). A manager at one shop opening
 * this page sees their tills, not the company's.
 */
class PosCounterController extends Controller
{
    public function __construct(
        protected PosCounterService $counters,
        protected FeatureService $features,
        protected PlanLimitService $limits,
    ) {}

    public function index(): View
    {
        return view('app.counters.index', [
            'counters' => PosCounter::query()
                ->with('branch')
                ->withCount('users')
                ->orderBy('branch_id')
                ->orderBy('name')
                ->get(),
            'meter' => $this->limits->meter(LimitRegistry::POS_COUNTERS),
            'multiCounter' => $this->features->enabled(FeatureRegistry::POS_MULTI_COUNTER),
        ]);
    }

    public function create(): View
    {
        return view('app.counters.create', [
            'counter' => new PosCounter,
            'branches' => $this->branchOptions(),
        ]);
    }

    public function store(PosCounterRequest $request): RedirectResponse
    {
        $counter = $this->counters->create($request->validated());

        return redirect()
            ->route('app.counters.index')
            ->with('success', "\"{$counter->name}\" added.");
    }

    public function edit(PosCounter $counter): View
    {
        return view('app.counters.edit', [
            'counter' => $counter,
            'branches' => $this->branchOptions(),
        ]);
    }

    public function update(PosCounterRequest $request, PosCounter $counter): RedirectResponse
    {
        $this->counters->update($counter, $request->validated());

        return redirect()
            ->route('app.counters.index')
            ->with('success', "\"{$counter->name}\" updated.");
    }

    public function toggle(PosCounter $counter): RedirectResponse
    {
        $this->counters->setActive($counter, ! $counter->is_active);

        return back()->with('success', "\"{$counter->name}\" ".($counter->is_active ? 'enabled' : 'disabled').'.');
    }

    public function destroy(PosCounter $counter): RedirectResponse
    {
        if (! $this->counters->delete($counter)) {
            return back()->with('error', 'Someone is assigned to that counter. Move them first, or just disable it.');
        }

        return redirect()
            ->route('app.counters.index')
            ->with('success', 'Counter deleted.');
    }

    /**
     * Branches this user may put a till in — the same list the service will
     * accept, so the form cannot offer a choice the save would reject.
     */
    protected function branchOptions()
    {
        return Branch::query()->accessible()->active()->ordered()->get(['id', 'name', 'code']);
    }
}
