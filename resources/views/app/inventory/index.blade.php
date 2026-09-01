<x-layouts.app title="Inventory">

    <x-flash />

    {{-- Summary: what is on hand, what it is worth, what needs attention (#28) --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                [
                    'label' => 'Shelves tracked',
                    'value' => number_format($valuation['shelves']),
                    'meta' => 'product × branch',
                    'icon' => 'inventory',
                    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                ],
                [
                    'label' => 'Units on hand',
                    'value' => rtrim(rtrim(number_format($valuation['quantity'], 4), '0'), '.'),
                    'meta' => 'across every branch you can see',
                    'icon' => 'products',
                    'tint' => 'text-violet-600 bg-violet-50 dark:bg-violet-500/10',
                ],
                [
                    'label' => 'Low stock',
                    'value' => number_format($valuation['low']),
                    'meta' => 'at or under their threshold',
                    'icon' => 'alert',
                    'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10',
                ],
                [
                    'label' => 'Out of stock',
                    'value' => number_format($valuation['out_of_stock']),
                    'meta' => 'nothing left to sell',
                    'icon' => 'ban',
                    'tint' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10',
                ],
            ];
        @endphp

        @foreach ($cards as $c)
            <div class="card p-5">
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $c['label'] }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{{ $c['value'] }}</p>
                        <p class="mt-1 truncate text-xs text-slate-400">{{ $c['meta'] }}</p>
                    </div>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $c['tint'] }}">
                        <x-icon :name="$c['icon']" class="h-5 w-5" />
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <form method="GET" action="{{ route('app.inventory.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                    <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                           placeholder="Product name, SKU or barcode" class="input" />
                </div>

                <div>
                    <label for="branch" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Branch</label>
                    <select id="branch" name="branch" class="input">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($filters['branch'] === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="status" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Status</label>
                    <select id="status" name="status" class="input">
                        <option value="">Any</option>
                        <option value="low" @selected($filters['status'] === 'low')>Low stock</option>
                        <option value="out" @selected($filters['status'] === 'out')>Out of stock</option>
                        <option value="negative" @selected($filters['status'] === 'negative')>Oversold</option>
                    </select>
                </div>

                <div class="flex items-end gap-2">
                    <button type="submit" class="btn btn-secondary">
                        <x-icon name="filter" class="h-4 w-4" /> Apply
                    </button>
                    @if (Route::has('app.inventory.expiry'))
                        <a href="{{ route('app.inventory.expiry') }}" class="btn btn-ghost">
                            <x-icon name="calendar" class="h-4 w-4" /> Batches &amp; expiry
                        </a>
                    @endif
                    @if (array_filter($filters))
                        <a href="{{ route('app.inventory.index') }}" class="btn btn-ghost">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="card p-5">
            @if ($canSeeCost)
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Stock value</p>
                <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                    {{ number_format($valuation['value'], 2) }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    At weighted average cost. Oversold shelves are included — a negative shelf is a real problem, not
                    one to hide from the total.
                </p>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Stock value is based on cost prices, which are hidden for your role.
                </p>
            @endif
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Product</th>
                        <th class="px-5 py-3 font-medium">Branch</th>
                        <th class="px-5 py-3 text-right font-medium">On hand</th>
                        <th class="px-5 py-3 text-right font-medium">Alert at</th>
                        @if ($canSeeCost)
                            <th class="px-5 py-3 text-right font-medium">Avg cost</th>
                            <th class="px-5 py-3 text-right font-medium">Value</th>
                        @endif
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($stocks as $stock)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $stock->label() }}</p>
                                <p class="text-xs text-slate-400">{{ $stock->product?->sku }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $stock->branch?->name ?? '—' }}</td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium {{ $stock->isNegative() ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                {{ rtrim(rtrim(number_format((float) $stock->quantity, 4), '0'), '.') }}
                                <span class="text-xs font-normal text-slate-400">{{ $stock->product?->unit?->short_name }}</span>
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                                @php $threshold = $stock->alertQuantity(); @endphp
                                {{ $threshold === null ? '—' : rtrim(rtrim(number_format($threshold, 4), '0'), '.') }}
                            </td>
                            @if ($canSeeCost)
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ number_format((float) $stock->average_cost, 2) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ number_format($stock->value(), 2) }}
                                </td>
                            @endif
                            <td class="px-5 py-3">
                                <span class="{{ $stock->statusBadgeClass() }}">{{ $stock->statusLabel() }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.inventory.ledger', $stock->product_id) }}"
                                       class="btn btn-ghost !px-2" title="Movement history">
                                        <x-icon name="history" class="h-4 w-4" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canSeeCost ? 8 : 6 }}" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    No stock movements yet. Stock appears here the first time something is purchased,
                                    adjusted or counted in.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($stocks->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                {{ $stocks->links() }}
            </div>
        @endif
    </div>

</x-layouts.app>
