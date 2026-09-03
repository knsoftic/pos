<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
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
    public function sheet(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'show_price' => ['boolean'],
            'show_name' => ['boolean'],
            'label_width' => ['nullable', 'numeric', 'min:20', 'max:120'],
        ]);

        $wanted = collect($validated['labels'] ?? [])
            ->map(fn ($count) => (int) $count)
            ->filter(fn ($count) => $count > 0);

        /*
         | ⚠️ EMPTY IS NOT AN ERROR. Every row on the form posts a quantity box,
         | all of them blank to begin with, so pressing the button before typing
         | anything is the single most likely thing to happen on this screen --
         | and it used to abort(422), which had no error view and so landed on
         | Symfony's own page announcing "Something is broken."
         |
         | Nothing was broken. The shop had not said how many labels it wanted.
         | Telling somebody their software is faulty because they have not
         | finished filling in a form teaches them to distrust every real error
         | that follows.
         |
         | ⚠️ And the form opens in a NEW TAB, so there is no going back with
         | the browser button -- the tab has no history. It has to be sent
         | somewhere useful under its own steam.
         */
        if ($wanted->isEmpty()) {
            /*
             | ⚠️ TWO DIFFERENT SITUATIONS LOOK IDENTICAL FROM HERE, and telling
             | them apart is the whole point of this branch.
             |
             |   Rows on screen, none filled in  -> "type a number beside one"
             |   No rows at all                  -> that advice is nonsense.
             |                                      There is nothing to type
             |                                      beside. The shop needs a
             |                                      BARCODE on a product first.
             |
             | Giving the first message in the second situation sends somebody
             | hunting the screen for a box that is not there.
             */
            $haveAny = Product::query()->whereNotNull('barcode')->active()->exists();

            return redirect()
                ->route('app.products.labels')
                ->with('error', $haveAny
                    ? 'Type how many labels you need beside a product, then open the sheet.'
                    : 'No product has a barcode yet, so there is nothing to print. Open a product, tick "Generate barcode", save — then come back here.');
        }

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

        // Same reasoning: a product can lose its barcode between loading the
        // list and submitting it. That is a thing to explain, not to fault.
        if ($labels === []) {
            return redirect()
                ->route('app.products.labels')
                ->with('error', 'None of those products has a barcode any more. Give them one first.');
        }

        return view('app.products.label-sheet', [
            'labels' => $labels,
            'showPrice' => $request->boolean('show_price'),
            'showName' => $request->boolean('show_name'),
            'labelWidth' => (float) ($validated['label_width'] ?? 50),
        ]);
    }
}
