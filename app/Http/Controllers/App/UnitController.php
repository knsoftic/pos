<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\UnitRequest;
use App\Models\Unit;
use App\Services\CatalogService;
use App\Services\FeatureService;
use App\Support\FeatureRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Units of measure (#26, #158).
 *
 * The conversion fields only appear when the plan includes `catalog.multi_unit`
 * — the service refuses a derived unit without it, so offering the field would
 * be inviting a refusal.
 */
class UnitController extends Controller
{
    public function __construct(
        protected CatalogService $catalog,
        protected FeatureService $features,
    ) {}

    public function index(): View
    {
        return view('app.units.index', [
            'units' => Unit::query()->ordered()->with('baseUnit')->withCount('products')->get(),
            'multiUnit' => $this->features->enabled(FeatureRegistry::CATALOG_MULTI_UNIT),
        ]);
    }

    public function create(): View
    {
        return view('app.units.create', $this->formData(new Unit));
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $unit = $this->catalog->createUnit($request->validated());

        return redirect()
            ->route('app.units.index')
            ->with('success', "\"{$unit->label()}\" added.");
    }

    public function edit(Unit $unit): View
    {
        return view('app.units.edit', $this->formData($unit));
    }

    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $this->catalog->updateUnit($unit, $request->validated());

        return redirect()
            ->route('app.units.index')
            ->with('success', "\"{$unit->label()}\" updated.");
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        if (! $this->catalog->deleteUnit($unit)) {
            return back()->with('error', 'That unit is in use by products or other units. Switch it off instead.');
        }

        return redirect()
            ->route('app.units.index')
            ->with('success', 'Unit deleted.');
    }

    /** @return array<string, mixed> */
    protected function formData(Unit $unit): array
    {
        return [
            'unit' => $unit,
            'multiUnit' => $this->features->enabled(FeatureRegistry::CATALOG_MULTI_UNIT),
            // Only base units may be converted to — one level, no chains.
            'baseUnits' => Unit::query()
                ->baseUnits()
                ->ordered()
                ->when($unit->exists, fn ($q) => $q->where('id', '!=', $unit->id))
                ->get(['id', 'name', 'short_name']),
        ];
    }
}
