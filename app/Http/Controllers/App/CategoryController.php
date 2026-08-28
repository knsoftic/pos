<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CategoryRequest;
use App\Models\Category;
use App\Services\CatalogService;
use App\Services\PlanLimitService;
use App\Support\LimitRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Categories and subcategories (#26). Thin — the rules (quota, parent
 * validation, archive-not-delete) all live in {@see CatalogService}.
 *
 * The index renders parents with their children rather than a flat list: two
 * levels is what the UI promises, and a shop should be able to see its filing
 * at a glance.
 */
class CategoryController extends Controller
{
    public function __construct(
        protected CatalogService $catalog,
        protected PlanLimitService $limits,
    ) {}

    public function index(): View
    {
        return view('app.categories.index', [
            'categories' => Category::query()
                ->roots()
                ->ordered()
                ->with(['children' => fn ($q) => $q->ordered()->withCount('products')])
                ->withCount('products')
                ->get(),
            'meter' => $this->limits->meter(LimitRegistry::CATEGORIES),
        ]);
    }

    public function create(): View
    {
        return view('app.categories.create', [
            'category' => new Category,
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $category = $this->catalog->createCategory($request->validated());

        return redirect()
            ->route('app.categories.index')
            ->with('success', "\"{$category->name}\" added.");
    }

    public function edit(Category $category): View
    {
        return view('app.categories.edit', [
            'category' => $category,
            'parents' => $this->parentOptions($category),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->catalog->updateCategory($category, $request->validated());

        return redirect()
            ->route('app.categories.index')
            ->with('success', "\"{$category->name}\" updated.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        if (! $this->catalog->deleteCategory($category)) {
            return back()->with('error', 'That category still holds products or subcategories. Move them first, or just switch it off.');
        }

        return redirect()
            ->route('app.categories.index')
            ->with('success', 'Category deleted.');
    }

    /**
     * Possible parents: root categories only (the UI promises two levels), and
     * never the category being edited.
     */
    protected function parentOptions(?Category $exclude = null)
    {
        return Category::query()
            ->roots()
            ->ordered()
            ->when($exclude?->exists, fn ($q) => $q->where('id', '!=', $exclude->id))
            ->get(['id', 'name']);
    }
}
