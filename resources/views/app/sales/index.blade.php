<x-layouts.app title="Sales">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                [
                    'label' => 'Sales',
                    'value' => number_format($totals['count']),
                    'meta' => $seesEverything ? 'in the filtered period' : 'yours, in the filtered period',
                    'icon' => 'sales',
                    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                ],
                [
                    'label' => 'Takings',
                    'value' => number_format($totals['takings'], 2),
                    'meta' => 'completed sales only',
                    'icon' => 'credit-card',
                    'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
                ],
                [
                    'label' => 'On account',
                    'value' => number_format($totals['on_credit'], 2),
                    'meta' => 'charged, not yet paid',
                    'icon' => 'customers',
                    'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10',
                ],
                [
                    'label' => 'Voided',
                    'value' => number_format($totals['voided']),
                    'meta' => 'kept on the record',
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

    <div class="card mb-5 p-5">
        <form method="GET" action="{{ route('app.sales.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="Invoice number, customer name or phone" class="input" />
            </div>

            <div>
                <label for="from" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">From</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input" />
            </div>

            <div>
                <label for="to" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">To</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input" />
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

            @if ($branches->count() > 1)
                <div>
                    <label for="branch" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Branch</label>
                    <select id="branch" name="branch" class="input">
                        <option value="">All</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($filters['branch'] === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($sellers->isNotEmpty())
                <div>
                    <label for="seller" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Sold by</label>
                    <select id="seller" name="seller" class="input">
                        <option value="">Anyone</option>
                        @foreach ($sellers as $seller)
                            <option value="{{ $seller->id }}" @selected($filters['seller'] === (string) $seller->id)>{{ $seller->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-center gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>

                <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="payment" value="credit" @checked($filters['payment'] === 'credit')
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                    On account only
                </label>

                @if (array_filter($filters))
                    <a href="{{ route('app.sales.index') }}" class="btn btn-ghost">Clear</a>
                @endif

                @can(\App\Support\PermissionRegistry::POS_OPERATE)
                    <a href="{{ route('app.pos.index') }}" class="btn btn-primary ml-auto">
                        <x-icon name="pos" class="h-4 w-4" /> Open the till
                    </a>
                @endcan
            </div>
        </form>

        @unless ($seesEverything)
            <p class="mt-3 text-xs text-slate-400">
                You are seeing your own sales. Someone with permission to see everyone's will see the whole shop here.
            </p>
        @endunless
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Invoice</th>
                        <th class="px-5 py-3 font-medium">Customer</th>
                        <th class="px-5 py-3 font-medium">Paid with</th>
                        <th class="px-5 py-3 text-right font-medium">Total</th>
                        @if ($canSeeProfit)
                            <th class="px-5 py-3 text-right font-medium">Profit</th>
                        @endif
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($sales as $sale)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <a href="{{ route('app.sales.show', $sale) }}"
                                   class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $sale->invoice_no }}
                                </a>
                                <p class="text-xs text-slate-400">
                                    {{ $sale->sold_at?->format('d M Y, H:i') }}
                                    @if ($seesEverything && $sale->seller)
                                        · {{ $sale->seller->name }}
                                    @endif
                                </p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $sale->customerName() }}</td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $sale->methodSummary() }}</td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                {{ number_format((float) $sale->total, 2) }}
                                @if ((float) $sale->due_amount > 0)
                                    <span class="block text-xs font-normal text-amber-600 dark:text-amber-400">
                                        {{ number_format((float) $sale->due_amount, 2) }} on account
                                    </span>
                                @endif
                            </td>
                            @if ($canSeeProfit)
                                <td class="px-5 py-3 text-right tabular-nums">
                                    @php $margin = $sale->marginPercent(); @endphp
                                    <span @class([
                                        'font-medium',
                                        'text-emerald-600 dark:text-emerald-400' => $sale->grossProfit() >= 0,
                                        'text-rose-600 dark:text-rose-400' => $sale->grossProfit() < 0,
                                    ])>{{ number_format($sale->grossProfit(), 2) }}</span>
                                    @if ($margin !== null)
                                        <span class="block text-xs text-slate-400">{{ number_format($margin, 1) }}%</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-5 py-3">
                                <span class="{{ $sale->status->badgeClass() }}">{{ $sale->status->label() }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.sales.receipt', [$sale, 'reprint' => 1]) }}" target="_blank"
                                       class="btn btn-ghost !px-2" title="Receipt">
                                        <x-icon name="printer" class="h-4 w-4" />
                                    </a>
                                    <a href="{{ route('app.sales.show', $sale) }}" class="btn btn-ghost !px-2" title="View">
                                        <x-icon name="arrow-right" class="h-4 w-4" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canSeeProfit ? 7 : 6 }}" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    No sales yet. Open the till and ring one up.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($sales->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $sales->links() }}</div>
        @endif
    </div>

</x-layouts.app>
