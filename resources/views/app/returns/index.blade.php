<x-layouts.app title="Returns">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Returns', 'value' => number_format($totals['count']), 'meta' => 'in the filtered period', 'icon' => 'refresh', 'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10'],
                ['label' => 'Value returned', 'value' => number_format($totals['value'], 2), 'meta' => 'off the takings', 'icon' => 'sales', 'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'],
                ['label' => 'Handed back', 'value' => number_format($totals['refunded'], 2), 'meta' => 'cash and card refunds', 'icon' => 'credit-card', 'tint' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10'],
                ['label' => 'Credited', 'value' => number_format($totals['credited'], 2), 'meta' => 'onto customer accounts', 'icon' => 'customers', 'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10'],
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
        <form method="GET" action="{{ route('app.returns.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="Return or invoice number, customer, reason" class="input" />
            </div>
            <div>
                <label for="from" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">From</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input" />
            </div>
            <div>
                <label for="to" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">To</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input" />
            </div>
            <div class="sm:col-span-4 flex items-center gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                @if (array_filter($filters))
                    <a href="{{ route('app.returns.index') }}" class="btn btn-ghost">Clear</a>
                @endif
                <a href="{{ route('app.sales.index') }}" class="btn btn-primary ml-auto">
                    <x-icon name="sales" class="h-4 w-4" /> Find a sale to return against
                </a>
            </div>
        </form>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Return</th>
                        <th class="px-5 py-3 font-medium">Against</th>
                        <th class="px-5 py-3 font-medium">Customer</th>
                        <th class="px-5 py-3 font-medium">Reason</th>
                        <th class="px-5 py-3 text-right font-medium">Value</th>
                        <th class="px-5 py-3 font-medium">Settled</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($returns as $return)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <a href="{{ route('app.returns.show', $return) }}"
                                   class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $return->reference }}
                                </a>
                                <p class="text-xs text-slate-400">
                                    {{ $return->return_date?->format('d M Y') }} · {{ $return->user?->name }}
                                </p>
                            </td>
                            <td class="px-5 py-3">
                                <a href="{{ route('app.sales.show', $return->sale_id) }}"
                                   class="text-slate-600 hover:text-brand-700 dark:text-slate-300 dark:hover:text-brand-300">
                                    {{ $return->sale?->invoice_no }}
                                </a>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $return->customerName() }}</td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                {{ $return->reason }}
                                @if ($return->writtenOffQuantity() > 0)
                                    <span class="badge-amber mt-1 block w-fit">
                                        {{ rtrim(rtrim(number_format($return->writtenOffQuantity(), 4), '0'), '.') }} written off
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                {{ number_format((float) $return->total, 2) }}
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-500 dark:text-slate-400">
                                {{ $return->settlementLabel() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    Nothing has come back yet. Open a sale to return against it.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($returns->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $returns->links() }}</div>
        @endif
    </div>

</x-layouts.app>
