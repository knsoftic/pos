<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\PermissionRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable barcode labels (#27).
 *
 * The screen IS the print job: a plain HTML sheet with a print stylesheet,
 * rather than a PDF. A shop prints these on whatever label paper it already
 * owns, so being able to nudge the size in the browser and hit Ctrl-P beats a
 * fixed PDF layout that fits nobody's stationery.
 *
 * Only products that actually have a barcode can appear — a label with no code
 * is a sticker, not a barcode label.
 */
class BarcodeLabelController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        return view('app.products.labels', [
            'products' => Product::query()
                ->whereNotNull('barcode')
                ->active()
                ->when($search !== '', fn ($q) => $q->search($search))
                ->orderBy('name')
                ->paginate(50)
                ->withQueryString(),
            'search' => $search,
            'canSeePrice' => $request->user()->can(PermissionRegistry::PRODUCTS_VIEW),
        ]);
    }

    /**
     * The sheet itself.
     *
     * Quantities arrive as `labels[productId] = count`, so one submission can
     * ask for three of one product and twenty of another — which is what
     * actually happens when a delivery is priced up.
     */
    public function sheet(Request $request): View
    {
        $validated = $request->validate([
            'labels' => ['required', 'array', 'min:1'],
            'labels.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'show_price' => ['boolean'],
            'show_name' => ['boolean'],
            'label_width' => ['nullable', 'numeric', 'min:20', 'max:120'],
        ]);

        $wanted = collect($validated['labels'])
            ->map(fn ($count) => (int) $count)
            ->filter(fn ($count) => $count > 0);

        abort_if($wanted->isEmpty(), 422, 'Choose how many labels you need.');

        $products = Product::query()
            ->whereIn('id', $wanted->keys())
            ->whereNotNull('barcode')
            ->get()
            ->keyBy('id');

        // Flattened here rather than in the view: a template that has to count
        // is a template that will one day print 200 of the wrong thing.
        $labels = [];

        foreach ($wanted as $productId => $count) {
            $product = $products->get((int) $productId);

            if ($product === null) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $labels[] = $product;
            }
        }

        abort_if($labels === [], 422, 'None of those products has a barcode.');

        return view('app.products.label-sheet', [
            'labels' => $labels,
            'showPrice' => $request->boolean('show_price'),
            'showName' => $request->boolean('show_name'),
            'labelWidth' => (float) ($validated['label_width'] ?? 50),
        ]);
    }
}
