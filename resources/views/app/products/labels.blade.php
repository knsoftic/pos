<x-layouts.app title="Barcode labels">

    <x-flash />
    @include('app.catalog-tabs')

    <form method="POST" action="{{ route('app.products.labels.sheet') }}" target="_blank">
        @csrf

        <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="card p-5 lg:col-span-2">
                <h3 class="font-semibold text-slate-900 dark:text-white">Print barcode labels</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Set how many labels you need of each product. The sheet opens in a new tab ready to print — on
                    whatever label paper you already use, so set the width to match it.
                </p>

                <div class="mt-4">
                    <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Find a product</label>
                    <input id="search" name="search" form="label-search" type="search" value="{{ $search }}"
                           placeholder="Name, SKU or barcode" class="input" />
                </div>
            </div>

            <div class="card space-y-3 p-5">
                <div>
                    <label for="label_width" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Label width
                    </label>
                    <div class="flex items-center gap-2">
                        <input id="label_width" name="label_width" type="number" min="20" max="120" step="1"
                               value="50" class="input" />
                        <span class="text-sm text-slate-400">mm</span>
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" name="show_name" value="1" checked
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">Print the product name</span>
                </label>

                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" name="show_price" value="1" checked
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">Print the price</span>
                </label>

                <button type="submit" class="btn btn-primary w-full">
                    <x-icon name="products" class="h-4 w-4" /> Open print sheet
                </button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">Product</th>
                            <th class="px-5 py-3 font-medium">Barcode</th>
                            <th class="px-5 py-3 text-right font-medium">Price</th>
                            <th class="px-5 py-3 text-right font-medium">Labels</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($products as $product)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $product->name }}</p>
                                    <p class="text-xs text-slate-400">{{ $product->sku }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="font-mono text-xs text-slate-600 dark:text-slate-300">{{ $product->barcode }}</span>
                                    @unless (\App\Support\Ean13::isValid($product->barcode))
                                        <span class="badge-amber ml-1">Not EAN-13</span>
                                    @endunless
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-700 dark:text-slate-300">
                                    {{ number_format((float) $product->selling_price, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <input type="number" name="labels[{{ $product->id }}]" min="0" max="200"
                                           placeholder="0" class="input !w-24 !py-1.5 text-right text-sm" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-slate-400">
                                    No products with a barcode yet. Add one on a product, or tick "generate one for me".
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $products->links() }}</div>
            @endif
        </div>
    </form>

    {{-- Kept out of the POST form so searching does not submit the label counts. --}}
    <form id="label-search" method="GET" action="{{ route('app.products.labels') }}"></form>

</x-layouts.app>
