<x-layouts.app title="Profit & Loss">

    <x-flash />

    @php
        $s = $statement;
        $money = fn ($v) => number_format((float) $v, 2);
        $sign = fn ($v) => (float) $v < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400';
    @endphp

    {{-- ───────────────────────────── the period ───────────────────────────── --}}
    <div class="card mb-5 p-5">
        <form method="GET" action="{{ route('app.reports.profit-loss') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div>
                <label for="from" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">From</label>
                <input id="from" name="from" type="date" value="{{ $s['from'] }}" class="input" />
            </div>
            <div>
                <label for="to" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">To</label>
                <input id="to" name="to" type="date" value="{{ $s['to'] }}" class="input" />
            </div>

            @if ($branches->count() > 1)
                <div>
                    <label for="branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Branch</label>
                    <select id="branch_id" name="branch_id" class="input">
                        <option value="">Whole business</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === $branch->id)>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                <button type="button" class="btn btn-ghost" onclick="window.print()">
                    <x-icon name="printer" class="h-4 w-4" /> Print
                </button>
            </div>

            <div class="sm:col-span-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                @foreach ([
                    'This month' => [now()->startOfMonth(), now()],
                    'Last month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                    'Last 30 days' => [now()->subDays(29), now()],
                    'This year' => [now()->startOfYear(), now()],
                ] as $label => $range)
                    <a href="{{ route('app.reports.profit-loss', array_filter([
                            'from' => $range[0]->toDateString(),
                            'to' => $range[1]->toDateString(),
                            'branch_id' => $filters['branch_id'],
                        ])) }}"
                       class="rounded-lg px-3 py-1.5 text-xs font-medium {{ $s['from'] === $range[0]->toDateString() && $s['to'] === $range[1]->toDateString()
                            ? 'bg-brand-600 text-white'
                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </form>
    </div>

    {{-- ──────────────────────────── the headlines ─────────────────────────── --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Revenue', 'value' => $money($s['revenue']['net']), 'meta' => 'net of returns and tax', 'icon' => 'sales', 'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10'],
                ['label' => 'Cost of goods sold', 'value' => $money($s['cogs']['net']), 'meta' => $s['cost_method'], 'icon' => 'inventory', 'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'],
                ['label' => 'Gross profit', 'value' => $money($s['gross_profit']), 'meta' => number_format($s['gross_margin'], 1).'% margin', 'icon' => 'trending-up', 'tint' => 'text-violet-600 bg-violet-50 dark:bg-violet-500/10'],
                ['label' => 'Net profit', 'value' => $money($s['net_profit']), 'meta' => number_format($s['net_margin'], 1).'% of revenue', 'icon' => 'reports', 'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10'],
            ];
        @endphp

        @foreach ($cards as $i => $c)
            <div class="card p-5">
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $c['label'] }}</p>
                        <p class="mt-2 text-2xl font-bold {{ $i >= 2 ? $sign($i === 2 ? $s['gross_profit'] : $s['net_profit']) : 'text-slate-900 dark:text-white' }}">
                            {{ $c['value'] }}
                        </p>
                        <p class="mt-1 truncate text-xs text-slate-400">{{ $c['meta'] }}</p>
                    </div>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $c['tint'] }}">
                        <x-icon :name="$c['icon']" class="h-5 w-5" />
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- ──────────────────────────── the statement ─────────────────────── --}}
        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">
                    Profit &amp; Loss
                    @if ($s['branch'])
                        <span class="text-slate-400">— {{ $s['branch']->name }}</span>
                    @endif
                </h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    {{ \Illuminate\Support\Carbon::parse($s['from'])->format('d M Y') }} to
                    {{ \Illuminate\Support\Carbon::parse($s['to'])->format('d M Y') }}
                    · {{ $s['days'] }} {{ Str::plural('day', $s['days']) }}
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        {{-- revenue --}}
                        <tr class="bg-slate-50/60 dark:bg-slate-800/30">
                            <td class="px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" colspan="2">
                                Revenue
                            </td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2.5 pl-8 text-slate-600 dark:text-slate-300">
                                Sales
                                <span class="text-xs text-slate-400">({{ number_format($s['revenue']['sales_count']) }})</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ $money($s['revenue']['gross']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2.5 pl-8 text-slate-600 dark:text-slate-300">
                                Less returns
                                <span class="text-xs text-slate-400">({{ number_format($s['revenue']['returns_count']) }})</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-rose-600 dark:text-rose-400">
                                @if ($s['revenue']['returns'] > 0) −@endif{{ $money($s['revenue']['returns']) }}
                            </td>
                        </tr>
                        <tr class="font-medium">
                            <td class="px-5 py-2.5 text-slate-900 dark:text-white">Net revenue</td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-900 dark:text-white">{{ $money($s['revenue']['net']) }}</td>
                        </tr>

                        {{-- cost of goods --}}
                        <tr class="bg-slate-50/60 dark:bg-slate-800/30">
                            <td class="px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" colspan="2">
                                Cost of goods sold
                            </td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2.5 pl-8 text-slate-600 dark:text-slate-300">Cost of what sold</td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ $money($s['cogs']['sold']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-5 py-2.5 pl-8 text-slate-600 dark:text-slate-300">
                                Less returns put back on the shelf
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                @if ($s['cogs']['restocked'] > 0) −@endif{{ $money($s['cogs']['restocked']) }}
                            </td>
                        </tr>
                        @if ($s['cogs']['written_off'] > 0)
                            <tr>
                                <td class="px-5 py-2.5 pl-8 text-slate-500 dark:text-slate-400">
                                    <span class="text-xs">of which written off and kept in cost</span>
                                    <span class="badge-amber ml-1">damaged</span>
                                </td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-xs text-slate-500 dark:text-slate-400">
                                    {{ $money($s['cogs']['written_off']) }}
                                </td>
                            </tr>
                        @endif
                        <tr class="font-medium">
                            <td class="px-5 py-2.5 text-slate-900 dark:text-white">Total cost of goods sold</td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-900 dark:text-white">{{ $money($s['cogs']['net']) }}</td>
                        </tr>

                        {{-- gross --}}
                        <tr class="bg-violet-50/60 dark:bg-violet-500/5">
                            <td class="px-5 py-3 font-semibold text-slate-900 dark:text-white">Gross profit</td>
                            <td class="px-5 py-3 text-right text-lg font-bold tabular-nums {{ $sign($s['gross_profit']) }}">
                                {{ $money($s['gross_profit']) }}
                                <span class="ml-1 text-xs font-medium text-slate-400">{{ number_format($s['gross_margin'], 1) }}%</span>
                            </td>
                        </tr>

                        {{-- expenses --}}
                        <tr class="bg-slate-50/60 dark:bg-slate-800/30">
                            <td class="px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" colspan="2">
                                Expenses
                            </td>
                        </tr>
                        @forelse ($s['expenses']['by_category'] as $row)
                            <tr>
                                <td class="px-5 py-2.5 pl-8 text-slate-600 dark:text-slate-300">
                                    {{ $row['name'] }}
                                    <span class="text-xs text-slate-400">({{ number_format($row['count']) }})</span>
                                </td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ $money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-5 py-2.5 pl-8 text-slate-400" colspan="2">Nothing recorded in this period.</td>
                            </tr>
                        @endforelse
                        <tr class="font-medium">
                            <td class="px-5 py-2.5 text-slate-900 dark:text-white">Total expenses</td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-rose-600 dark:text-rose-400">
                                @if ($s['expenses']['total'] > 0) −@endif{{ $money($s['expenses']['total']) }}
                            </td>
                        </tr>

                        {{-- other income --}}
                        <tr>
                            <td class="px-5 py-2.5 text-slate-600 dark:text-slate-300">
                                Other income
                                <span class="text-xs text-slate-400">({{ number_format($s['other_income']['count']) }})</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                @if ($s['other_income']['total'] > 0) +@endif{{ $money($s['other_income']['total']) }}
                            </td>
                        </tr>

                        {{-- net --}}
                        <tr class="bg-emerald-50/60 dark:bg-emerald-500/5">
                            <td class="px-5 py-3.5 font-semibold text-slate-900 dark:text-white">Net profit</td>
                            <td class="px-5 py-3.5 text-right text-xl font-bold tabular-nums {{ $sign($s['net_profit']) }}">
                                {{ $money($s['net_profit']) }}
                                <span class="ml-1 text-xs font-medium text-slate-400">{{ number_format($s['net_margin'], 1) }}%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-5 py-3 text-xs text-slate-400 dark:border-slate-800">
                Costed at <strong>{{ $s['cost_method'] }}</strong>, snapshotted when each sale happened — so a
                delivery at a new price never rewrites a month that has already closed. Sales tax is excluded from
                revenue: the shop collects it, it does not earn it.
            </div>
        </div>

        {{-- ───────────────────────────── the shape ────────────────────────── --}}
        <div class="space-y-5">
            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">Where the money went</h3>
                <p class="mt-0.5 text-xs text-slate-400">Every 100 of revenue, split.</p>

                @php
                    $revenue = max(0.01, (float) $s['revenue']['net']);
                    $bars = [
                        ['label' => 'Cost of goods', 'value' => (float) $s['cogs']['net'], 'class' => 'bg-amber-500'],
                        ['label' => 'Expenses', 'value' => (float) $s['expenses']['total'], 'class' => 'bg-rose-500'],
                        ['label' => 'Kept as profit', 'value' => max(0, (float) $s['net_profit']), 'class' => 'bg-emerald-500'],
                    ];
                @endphp

                <div class="mt-4 space-y-3">
                    @foreach ($bars as $bar)
                        @php $share = min(100, max(0, ($bar['value'] / $revenue) * 100)); @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <span class="text-slate-600 dark:text-slate-300">{{ $bar['label'] }}</span>
                                <span class="tabular-nums text-slate-500 dark:text-slate-400">
                                    {{ number_format($share, 1) }}% · {{ $money($bar['value']) }}
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                <div class="h-full rounded-full {{ $bar['class'] }}" style="width: {{ $share }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($s['net_profit'] < 0)
                    <p class="mt-4 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        This period cost more than it earned. Gross profit was {{ $money($s['gross_profit']) }} and
                        expenses were {{ $money($s['expenses']['total']) }}.
                    </p>
                @endif
            </div>

            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">Day by day</h3>
                <div id="plChart" class="mt-2 min-h-[240px]"></div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('plChart');

            if (! el || typeof window.loadCharts !== 'function') {
                return;
            }

            const days = @json($daily->values());

            const options = (dark) => {
                const base = window.chartDefaults(dark);

                return {
                    ...base,
                    chart: { ...base.chart, type: 'bar', height: 240, stacked: false },
                    colors: ['#1f4ded', '#10b981'],
                    series: [
                        { name: 'Revenue', data: days.map((d) => d.revenue) },
                        { name: 'Net profit', data: days.map((d) => d.net_profit) },
                    ],
                    xaxis: {
                        categories: days.map((d) => d.date),
                        // A quarter has 90 labels and room for about six. Let
                        // Apex drop the ones that would collide rather than
                        // printing them on top of each other.
                        tickAmount: Math.min(days.length, 6),
                        labels: {
                            rotate: days.length > 14 ? -45 : 0,
                            rotateAlways: false,
                            hideOverlappingLabels: true,
                            formatter: (v) => (v || '').slice(5),
                        },
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                    },
                    yaxis: { labels: { formatter: (v) => Math.round(v).toLocaleString() } },
                    plotOptions: { bar: { columnWidth: days.length > 45 ? '90%' : '55%', borderRadius: 2 } },
                    legend: { position: 'top', horizontalAlign: 'right' },
                };
            };

            window.loadCharts().then((ApexCharts) => {
                const chart = new ApexCharts(el, options(document.documentElement.classList.contains('dark')));
                chart.render();

                window.addEventListener('theme-changed', (e) => {
                    chart.updateOptions(options(e.detail.dark), false, false);
                });
            });
        });
    </script>
    @endpush

</x-layouts.app>
