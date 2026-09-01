<?php

namespace App\Http\Controllers\App;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\FeatureService;
use App\Services\PlanLimitService;
use App\Services\ProductService;
use App\Support\FeatureRegistry;
use App\Support\LimitRegistry;
use App\Support\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The catalogue (#24, #25, #105).
 *
 * COST PRICES ARE A PERMISSION, NOT A COLUMN YOU JUST RENDER (#52). The list and
 * the form both ask `products.view_cost` before showing cost or margin, and the
 * form request drops the submitted cost when the user may not see it — so a
 * cashier editing a product's name can never blank its cost by posting the form
 * they were shown.
 */
class ProductController extends Controller
{
    public function __construct(
        protected ProductService $products,
        protected FeatureService $features,
        protected PlanLimitService $limits,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', ''),
            'brand' => (string) $request->query('brand', ''),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $products = Product::query()
            ->with(['category', 'brand', 'unit', 'variants'])
            ->when($filters['search'] !== '', fn ($q) => $q->search($filters['search']))
            ->when($filters['category'] !== '', fn ($q) => $q->where('category_id', (int) $filters['category']))
            ->when($filters['brand'] !== '', fn ($q) => $q->where('brand_id', (int) $filters['brand']))
            ->when($filters['type'] !== '', fn ($q) => $q->where('type', $filters['type']))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            // Catalogues get long; never render them whole (#97, #167).
            ->paginate(25)
            ->withQueryString();

        return view('app.products.index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::query()->ordered()->get(['id', 'name', 'parent_id']),
            'brands' => Brand::query()->ordered()->get(['id', 'name']),
            'meter' => $this->limits->meter(LimitRegistry::PRODUCTS),
            'canSeeCost' => $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST),
        ]);
    }

    public function create(Request $request): View
    {
        return view('app.products.create', $this->formData(new Product, $request));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->create($request->productAttributes(), $request->variantRows());

        return redirect()
            ->route('app.products.index')
            ->with('success', "\"{$product->name}\" added.");
    }

    public function edit(Request $request, Product $product): View
    {
        $product->load('variants');

        return view('app.products.edit', $this->formData($product, $request));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->productAttributes($product), $request->variantRows());

        return redirect()
            ->route('app.products.index')
            ->with('success', "\"{$product->name}\" updated.");
    }

    /** Active / Inactive (#105) — an inactive product keeps all its history. */
    public function toggle(Product $product): RedirectResponse
    {
        $this->products->setActive($product, ! $product->is_active);

        return back()->with('success', "\"{$product->name}\" ".($product->is_active ? 'activated' : 'deactivated').'.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        if (! $this->products->delete($product)) {
            return back()->with('error', 'That product has history and cannot be deleted. Deactivate it instead.');
        }

        return redirect()
            ->route('app.products.index')
            ->with('success', 'Product deleted.');
    }

    /** @return array<string, mixed> */
    protected function formData(Product $product, Request $request): array
    {
        return [
            'product' => $product,
            'categories' => Category::query()->active()->ordered()->with('parent:id,name')->get(),
            'brands' => Brand::query()->active()->ordered()->get(['id', 'name']),
            'units' => Unit::query()->active()->ordered()->get(['id', 'name', 'short_name']),
            'types' => ProductType::cases(),
            'canSeeCost' => $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST),
            'variantsEnabled' => $this->features->enabled(FeatureRegistry::CATALOG_VARIANTS),
            'batchesEnabled' => $this->features->anyOf([
                FeatureRegistry::INVENTORY_EXPIRY_TRACKING,
                FeatureRegistry::CATALOG_BATCH_TRACKING,
            ]),
        ];
    }
}
