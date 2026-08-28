<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\BrandRequest;
use App\Models\Brand;
use App\Services\CatalogService;
use App\Services\PlanLimitService;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Brands (#26). Same shape as categories, minus the hierarchy.
 */
class BrandController extends Controller
{
    public function __construct(
        protected CatalogService $catalog,
        protected PlanLimitService $limits,
    ) {}

    public function index(): View
    {
        return view('app.brands.index', [
            'brands' => Brand::query()->ordered()->withCount('products')->get(),
            'meter' => $this->limits->meter(LimitRegistry::BRANDS),
        ]);
    }

    public function create(): View
    {
        return view('app.brands.create', ['brand' => new Brand]);
    }

    public function store(BrandRequest $request): RedirectResponse
    {
        $brand = $this->catalog->createBrand($request->validated());

        return redirect()
            ->route('app.brands.index')
            ->with('success', "\"{$brand->name}\" added.");
    }

    public function edit(Brand $brand): View
    {
        return view('app.brands.edit', ['brand' => $brand]);
    }

    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $this->catalog->updateBrand($brand, $request->validated());

        return redirect()
            ->route('app.brands.index')
            ->with('success', "\"{$brand->name}\" updated.");
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        if (! $this->catalog->deleteBrand($brand)) {
            return back()->with('error', 'That brand still has products. Move them first, or just switch it off.');
        }

        return redirect()
            ->route('app.brands.index')
            ->with('success', 'Brand deleted.');
    }
}
