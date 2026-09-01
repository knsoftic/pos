<x-layouts.app title="Purchases">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @php
            $cards = [
                [
                    'label' => 'Open orders',
                    'value' => number_format($totals['open']),
                    'meta' => 'drafted, ordered or part-delivered',
                    'icon' => 'purchases',
                    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                ],
                [
                    'label' => 'Unpaid bills',
                    'value' => number_format($totals['unpaid_value'], 2),
                    'meta' => 'on goods already received',
                    'icon' => 'credit-card',
                    'tint' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10',
                ],
                [
                    'label' => 'Received this month',
                    'value' => number_format($totals['received_this_month'], 2),
                    'meta' => 'value of goods taken in',
                    'icon' => 'inventory',
                    'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
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

    <div class="card mb-5 p-5">
        <form method="GET" action="{{ route('app.purchases.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="PO number, supplier invoice or supplier" class="input" />
            </div>

            <div>
                <label for="status" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Status</label>
                <select id="status" name="status" class="input">
                    <option value="">Any</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="supplier" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Supplier</label>
                <select id="supplier" name="supplier" class="input">
                    <option value="">Any</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected($filters['supplier'] === (string) $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-4 flex flex-wrap items-center gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>

                <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="payment" value="unpaid" @checked($filters['payment'] === 'unpaid')
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                    Unpaid only
                </label>

                @if (array_filter($filters))
                    <a href="{{ route('app.purchases.index') }}" class="btn btn-ghost">Clear</a>
                @endif

                @can(\App\Support\PermissionRegistry::PURCHASES_CREATE)
                    <a href="{{ route('app.purchases.create') }}" class="btn btn-primary ml-auto">
                        <x-icon name="plus" class="h-4 w-4" /> New purchase
                    </a>
                @endcan
            </div>
        </form>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Reference</th>
                        <th class="px-5 py-3 font-medium">Supplier</th>
                        <th class="px-5 py-3 font-medium">Ordered</th>
                        <th class="px-5 py-3 text-right font-medium">Total</th>
                        <th class="px-5 py-3 text-right font-medium">Outstanding</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($purchases as $purchase)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <a href="{{ route('app.purchases.show', $purchase) }}"
                                   class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $purchase->reference }}
                                </a>
                                <p class="text-xs text-slate-400">
                                    {{ $purchase->branch?->name }}
                                    @if ($purchase->supplier_invoice_no)
                                        · {{ $purchase->supplier_invoice_no }}
                                    @endif
                                </p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $purchase->supplier?->name }}</td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                {{ $purchase->order_date?->format('d M Y') }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                {{ number_format((float) $purchase->total, 2) }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                @php $outstanding = $purchase->outstandingQuantity(); @endphp
                                @if ($outstanding > 0 && $purchase->status->isOpen())
                                    <span class="text-amber-600 dark:text-amber-400">
                                        {{ rtrim(rtrim(number_format($outstanding, 4), '0'), '.') }} units
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $purchase->status->badgeClass() }}">{{ $purchase->status->label() }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $purchase->paymentBadgeClass() }}">{{ $purchase->paymentLabel() }}</span>
                                @if ($purchase->status->hasPosted() && ! $purchase->isSettled())
                                    <p class="mt-0.5 text-xs text-slate-400">
                                        {{ number_format($purchase->balanceDue(), 2) }} due
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    No purchases yet. Raise one when you order stock from a supplier.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($purchases->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $purchases->links() }}</div>
        @endif
    </div>

</x-layouts.app>
