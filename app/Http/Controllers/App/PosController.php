<?php

namespace App\Http\Controllers\App;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\PosCheckoutRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\CashSessionService;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The selling screen (#14, #15, #16, #20, #90, #122).
 *
 * ================= WHY THE CART LIVES IN THE BROWSER =================
 * Every cart action — a quantity nudge, a line discount, removing something —
 * is instant, because none of it touches the server. The till asks the server
 * exactly two kinds of question: "what products match this?" and "here is a
 * finished sale". A shop's connection is usually the worst part of its
 * infrastructure, and a POS that needs a round trip to add a bottle of water to
 * a basket is unusable on it (#122).
 *
 * That also means the cart is NOT authoritative. Prices, stock, credit limits
 * and totals are all recalculated by {@see SaleService} from the ids the browser
 * sends — a cart that says a 500-rupee item costs 5 is simply wrong, and the
 * server prices it properly.
 *
 * ================= SEARCH =================
 * Server-side and debounced rather than a preloaded catalogue: a tenant on the
 * Business plan may have thousands of products, and shipping all of them to
 * every till on every page load would trade one slow moment for a permanent one.
 * Favourites (#147) are preloaded instead, so the grid is full the instant the
 * screen opens.
 */
class PosController extends Controller
{
    public function __construct(
        protected SaleService $sales,
        protected InventoryService $inventory,
        protected CashSessionService $cashSessions,
    ) {}

    /** The till itself. */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('app.pos.index', [
            // The grid's opening contents: what this shop sells all day (#147).
            'favourites' => $this->grid(fn (Builder $q) => $q->where('is_favourite', true)),

            // Something to show when nothing is pinned yet.
            'recent' => $this->grid(fn (Builder $q) => $q->orderByDesc('id'), 12),

            'categories' => Category::query()->active()->roots()->ordered()->get(['id', 'name']),

            // A short list so the customer picker is useful before typing (#16).
            'customers' => Customer::query()->active()->orderBy('name')->limit(50)->get([
                'id', 'name', 'phone', 'credit_limit', 'balance',
            ]),

            'session' => $this->cashSessions->currentFor($user->branch_id, $user->pos_counter_id),
            'requiresSession' => (bool) config('pos.require_cash_session', false),
            'paymentMethods' => (array) config('pos.payment_methods', []),

            // The shop's own wallet or bank code, if they have uploaded one
            // (#57). Shown full-screen at the till for a customer to scan.
            'paymentQr' => config('pos.payment_qr_path'),
            'creditMethod' => (string) config('pos.credit_method', 'credit'),
            'cashMethods' => (array) config('pos.cash_methods', ['cash']),
            'rounding' => (float) config('pos.cash_rounding', 0),
            /*
             | ⚠️ `format.*`, NOT `subscription.*`. This read
             | `subscription.currency_symbol` — what KN Softic charges the SHOP
             | — so every till printed the platform's billing currency instead
             | of the one the shop sells in. A Lahore shop taking rupees saw
             | dollars on every line.
             |
             | The cart lives in the browser, so the till formats money in
             | JavaScript and cannot call Format::money(). The whole format goes
             | over instead of just the symbol: position, decimals and both
             | separators are shop settings too, and a till that honoured only
             | the symbol would still be wrong for anyone who does not write
             | 1,234.56.
             */
            'money' => [
                'symbol' => (string) config('format.currency_symbol', ''),
                'position' => (string) config('format.currency_position', 'before'),
                'decimals' => (int) config('format.decimals', 2),
                'thousands' => (string) config('format.thousands_separator', ','),
                'decimal' => (string) config('format.decimal_separator', '.'),
            ],

            'holds' => Sale::query()->held()->with('customer:id,name')->latest('id')->get([
                'id', 'invoice_no', 'customer_id', 'total', 'created_at',
            ]),

            'canDiscount' => $user->can(PermissionRegistry::POS_DISCOUNT),
            'discountCap' => $user->discountCap(),
            'canAddCustomer' => $user->can(PermissionRegistry::CUSTOMERS_MANAGE),
        ]);
    }

    /**
     * Instant product search (#15).
     *
     * Returns JSON, not a view: this is called on every few keystrokes and the
     * screen never reloads (#90).
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category');

        $products = $this->grid(function (Builder $q) use ($term, $categoryId): void {
            $q->when($term !== '', fn (Builder $b) => $b->search($term))
                ->when(filled($categoryId), fn (Builder $b) => $b->where(function (Builder $c) use ($categoryId): void {
                    // A parent category shows its children's products too (#148),
                    // which is what someone clicking "Drinks" expects.
                    $c->where('category_id', (int) $categoryId)
                        ->orWhereHas('category', fn (Builder $cat) => $cat->where('parent_id', (int) $categoryId));
                }))
                ->orderBy('name');
        }, 40);

        return response()->json(['products' => $products]);
    }

    /**
     * A scanned barcode (#15).
     *
     * Exact match only, and it answers with ONE product: a scanner has given an
     * unambiguous answer, so offering a list to choose from would be a step
     * backwards. Variants carry their own barcodes, so the reply says which
     * variant was scanned.
     */
    public function scan(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('barcode', ''));

        if ($code === '') {
            return response()->json(['product' => null]);
        }

        $product = Product::query()
            ->active()
            ->with('variants:id,product_id,name,sku,barcode,selling_price,is_active')
            ->where(fn (Builder $q) => $q
                ->where('barcode', $code)
                ->orWhere('sku', $code)
                ->orWhereHas('variants', fn (Builder $v) => $v->where('barcode', $code)->orWhere('sku', $code)))
            ->first();

        if ($product === null) {
            return response()->json(['product' => null]);
        }

        $variant = $product->variants->first(fn ($v) => $v->barcode === $code || $v->sku === $code);

        return response()->json([
            'product' => $this->present($product, $variant?->id),
        ]);
    }

    /**
     * Take the money (#118).
     *
     * Everything that matters happens in {@see SaleService}; this only turns the
     * cart into the shape the service expects and turns the result into JSON the
     * till can act on without reloading (#90).
     */
    public function checkout(PosCheckoutRequest $request): JsonResponse
    {
        // #91: the same cart submitted twice — a double tap, a retry after a
        // timeout — must produce one sale, not two. The key is on the cart, so
        // a genuine second sale of the same items carries a different one.
        $existing = $request->existingSale();

        if ($existing !== null) {
            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'sale' => $this->saleSummary($existing),
            ]);
        }

        $sale = $this->sales->complete(
            $request->saleAttributes(),
            $request->lines(),
            $request->payments(),
        );

        return response()->json([
            'ok' => true,
            'sale' => $this->saleSummary($sale),
        ]);
    }

    /** Park the cart (#20). */
    public function hold(PosCheckoutRequest $request): JsonResponse
    {
        $sale = $this->sales->hold($request->saleAttributes(), $request->lines());

        return response()->json([
            'ok' => true,
            'sale' => ['id' => $sale->id, 'reference' => $sale->invoice_no],
        ]);
    }

    /** Bring a held cart back to the till. */
    public function resumeHold(Sale $sale): JsonResponse
    {
        abort_unless($sale->status === SaleStatus::Held, 422, 'That sale is not on hold.');

        $sale->load(['items.product:id,name,sku,type,track_inventory', 'items.variant:id,name']);

        return response()->json([
            'ok' => true,
            'customer_id' => $sale->customer_id,
            'lines' => $sale->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'name' => $item->description,
                'sku' => $item->product?->sku,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount_amount' => (float) $item->discount_amount,
                'tax_rate' => (float) $item->tax_rate,
            ])->values(),
        ]);
    }

    public function discardHold(Sale $sale): JsonResponse
    {
        abort_unless($this->sales->discardHold($sale), 422, 'Only a held sale can be discarded.');

        return response()->json(['ok' => true]);
    }

    /**
     * Add a customer without leaving the till (#146).
     *
     * The whole point is that it takes one field. A shop asked for a phone
     * number at the counter is not going to fill in a tax number.
     */
    public function quickCustomer(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can(PermissionRegistry::CUSTOMERS_MANAGE),
            403,
            'You cannot add customers.',
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $customer = app(CustomerService::class)->create($validated);

        return response()->json([
            'ok' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'balance' => (float) $customer->balance,
                'credit_limit' => $customer->credit_limit === null ? null : (float) $customer->credit_limit,
            ],
        ]);
    }

    /** Pin or unpin a product on the grid (#147). */
    public function toggleFavourite(Product $product): JsonResponse
    {
        $product->is_favourite = ! $product->is_favourite;
        $product->save();

        return response()->json(['ok' => true, 'is_favourite' => $product->is_favourite]);
    }

    /** Open the drawer from the till itself (#139). */
    public function openSession(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->cashSessions->open([
            'opening_float' => (float) $validated['opening_float'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Till opened.');
    }

    // ------------------------------------------------------------- internals

    /**
     * The grid's product shape, with what is on the shelf right now.
     *
     * @param  callable(Builder): mixed  $filter
     */
    protected function grid(callable $filter, int $limit = 60): Collection
    {
        $query = Product::query()
            ->active()
            ->with(['variants' => fn ($v) => $v->where('is_active', true)]);

        $filter($query);

        return $query->limit($limit)->get()->map(fn (Product $p) => $this->present($p));
    }

    /** @return array<string, mixed> */
    protected function present(Product $product, ?int $variantId = null): array
    {
        $range = $product->priceRange();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (float) $product->selling_price,
            'price_from' => $range['min'],
            'price_to' => $range['max'],
            'tax_rate' => (float) ($product->tax_rate ?? 0),
            'tracks_stock' => $product->tracksStock(),
            'is_favourite' => (bool) $product->is_favourite,
            'image' => $product->image_path
                ? Storage::disk(config('uploads.products.disk'))->url($product->image_path)
                : null,
            // What is on this till's shelf. Advisory: the authoritative check is
            // the row lock at checkout (#70), because anything read now can be
            // sold by another till a second later.
            'stock' => $product->tracksStock()
                ? $this->inventory->getAvailableStock($product)
                : null,
            'selected_variant_id' => $variantId,
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'sku' => $v->sku,
                'price' => (float) $v->selling_price,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    protected function saleSummary(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'total' => (float) $sale->total,
            'paid' => (float) $sale->paid_total,
            'change' => (float) $sale->change_given,
            'due' => (float) $sale->due_amount,
            'customer' => $sale->customerName(),
        ];
    }
}
