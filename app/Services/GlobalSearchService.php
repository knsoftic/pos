<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Support\FeatureRegistry;
use App\Support\Format;
use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The search box in the top bar (#75).
 *
 * ================= WHAT IT IS FOR =================
 * Finding one specific thing whose name or number somebody already knows — a
 * product, a customer, an invoice. It is NOT a report and not a filter: it
 * returns a handful of results and links straight to them.
 *
 * ================= EVERY SOURCE IS GATED =================
 * A search box that quietly returns rows from a module somebody cannot open is
 * a leak with a friendly interface. Each source is behind the same permission
 * and the same plan feature as its screen, so what comes back is exactly what
 * the person could have navigated to anyway.
 *
 * ================= AND IT STAYS CHEAP =================
 * Every query is `LIKE 'term%'` or an exact match on an indexed column, capped
 * at five rows a source. A leading wildcard cannot use an index, and a search
 * box that gets slower as a shop grows is a search box people stop using — so
 * a barcode or an invoice number matches exactly, and names match from the
 * start.
 */
class GlobalSearchService
{
    /** Below this a search is noise: two letters match half the catalogue. */
    public const MIN_LENGTH = 2;

    protected const PER_SOURCE = 5;

    public function __construct(protected FeatureService $features) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $term): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return collect();
        }

        $user = auth('web')->user();

        if ($user === null) {
            return collect();
        }

        return collect()
            ->concat($this->products($user, $term))
            ->concat($this->customers($user, $term))
            ->concat($this->suppliers($user, $term))
            ->concat($this->sales($user, $term))
            ->concat($this->purchases($user, $term))
            ->values();
    }

    // ------------------------------------------------------------ the sources

    /** @return Collection<int, array<string, mixed>> */
    protected function products($user, string $term): Collection
    {
        if (! $user->can(PermissionRegistry::PRODUCTS_VIEW)) {
            return collect();
        }

        $prefix = $this->prefix($term);

        return Product::query()
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $prefix)
                ->orWhere('sku', 'like', $prefix)
                // A barcode is scanned or typed in full — a prefix match on one
                // is meaningless, and an exact match can use the unique index.
                ->orWhere('barcode', $term))
            ->orderBy('name')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'name', 'sku', 'barcode'])
            ->map(fn (Product $product) => [
                'group' => 'Products',
                'icon' => 'products',
                'title' => $product->name,
                'meta' => $product->sku,
                // Products have no detail screen — the edit form IS the
                // detail, so that is where "find this product" should land.
                'href' => $user->can(PermissionRegistry::PRODUCTS_UPDATE)
                    ? route('app.products.edit', $product)
                    : route('app.products.index', ['search' => $product->sku]),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function customers($user, string $term): Collection
    {
        if (! $user->can(PermissionRegistry::CUSTOMERS_VIEW)
            || ! $this->features->enabled(FeatureRegistry::CUSTOMERS_MANAGEMENT)) {
            return collect();
        }

        $prefix = $this->prefix($term);

        return Customer::query()
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $prefix)
                ->orWhere('phone', 'like', $prefix)
                ->orWhere('email', 'like', $prefix))
            ->orderBy('name')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'name', 'phone', 'balance'])
            ->map(fn (Customer $customer) => [
                'group' => 'Customers',
                'icon' => 'customers',
                'title' => $customer->name,
                'meta' => $customer->phone ?: 'No phone',
                'href' => route('app.customers.show', $customer),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function suppliers($user, string $term): Collection
    {
        if (! $user->can(PermissionRegistry::SUPPLIERS_VIEW)
            || ! $this->features->enabled(FeatureRegistry::PURCHASES_SUPPLIER_LEDGER)) {
            return collect();
        }

        $prefix = $this->prefix($term);

        return Supplier::query()
            ->where(fn (Builder $q) => $q->where('name', 'like', $prefix)->orWhere('phone', 'like', $prefix))
            ->orderBy('name')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'name', 'phone'])
            ->map(fn (Supplier $supplier) => [
                'group' => 'Suppliers',
                'icon' => 'suppliers',
                'title' => $supplier->name,
                'meta' => $supplier->phone ?: 'No phone',
                'href' => route('app.suppliers.show', $supplier),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function sales($user, string $term): Collection
    {
        if (! $user->can(PermissionRegistry::SALES_VIEW)
            || ! $this->features->enabled(FeatureRegistry::SALES_INVOICING)) {
            return collect();
        }

        return Sale::query()
            ->where('status', SaleStatus::Completed)
            ->where('invoice_no', 'like', $this->prefix($term))
            // Same narrowing as the sales book: `sales.view` is your own,
            // `sales.view_all` is everybody's — decided in the query (#21).
            ->when(! $user->can(PermissionRegistry::SALES_VIEW_ALL),
                fn (Builder $q) => $q->where('user_id', $user->id))
            ->with('customer:id,name')
            ->latest('id')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Sale $sale) => [
                'group' => 'Sales',
                'icon' => 'sales',
                'title' => $sale->invoice_no,
                'meta' => $sale->customerName().' · '.Format::money($sale->total, true),
                'href' => route('app.sales.show', $sale),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function purchases($user, string $term): Collection
    {
        if (! $user->can(PermissionRegistry::PURCHASES_VIEW)
            || ! $this->features->enabled(FeatureRegistry::PURCHASES_ORDERS)) {
            return collect();
        }

        $prefix = $this->prefix($term);

        return Purchase::query()
            ->where(fn (Builder $q) => $q
                ->where('reference', 'like', $prefix)
                ->orWhere('supplier_invoice_no', 'like', $prefix))
            ->with('supplier:id,name')
            ->latest('id')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Purchase $purchase) => [
                'group' => 'Purchases',
                'icon' => 'purchases',
                'title' => $purchase->reference,
                'meta' => ($purchase->supplier?->name ?? 'No supplier').' · '.$purchase->status->label(),
                'href' => route('app.purchases.show', $purchase),
            ]);
    }

    /**
     * `term%`, with the wildcards the user typed escaped.
     *
     * ⚠️ No LEADING wildcard, deliberately. `%term%` cannot use an index, so it
     * turns every search into a full scan — fine on a demo, useless in a shop
     * with 40,000 products, which is exactly the shop that needs search most.
     */
    protected function prefix(string $term): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $term).'%';
    }
}
