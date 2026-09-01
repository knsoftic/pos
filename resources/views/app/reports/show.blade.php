<x-layouts.app :title="$report['name']">

    <x-flash />

    @php
        $meta = $report['meta'];

        /*
        | One formatter for every report. A column says what KIND of thing it
        | holds and this decides how it reads — so a money column can never be
        | right-aligned in one report and left in another.
        */
        $render = function ($value, array $column) {
            $format = $column['format'] ?? 'text';

            if ($value === null || $value === '') {
                return $format === 'text' || $format === 'date' ? '—' : '';
            }

            return match ($format) {
                'money' => number_format((float) $value, 2),
                'number' => number_format((float) $value),
                'quantity' => rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.'),
                'percent' => number_format((float) $value, 1).'%',
                'date' => \Illuminate\Support\Carbon::parse($value)->format('d M Y'),
                default => $value,
            };
        };

        $align = fn (array $column) => ($column['align'] ?? 'left') === 'right' ? 'text-right' : 'text-left';
    @endphp

    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('app.reports.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
                <x-icon name="arrow-left" class="h-4 w-4" /> All reports
            </a>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $report['description'] }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn btn-ghost" onclick="window.print()">
                <x-icon name="printer" class="h-4 w-4" /> Print
            </button>

            @can(\App\Support\PermissionRegistry::REPORTS_EXPORT)
                @foreach ($formats as $format => $label)
                    <a href="{{ route('app.reports.export', array_merge(['report' => $report['key'], 'format' => $format], $query)) }}"
                       class="btn btn-secondary">
                        <x-icon name="archive" class="h-4 w-4" /> {{ $label }}
                    </a>
                @endforeach
            @endcan
        </div>
    </div>

    {{-- ───────────────────────────── the filters (#55) ────────────────────── --}}
    <div class="card mb-5 p-5">
        <form method="GET" action="{{ route('app.reports.show', $report['key']) }}"
              class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">

            @if (in_array('period', $definition['filters'], true))
                <div>
                    <label for="from" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">From</label>
                    <input id="from" name="from" type="date" value="{{ $meta['from'] }}" class="input" />
                </div>
                <div>
                    <label for="to" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">To</label>
                    <input id="to" name="to" type="date" value="{{ $meta['to'] }}" class="input" />
                </div>
            @endif

            @if (in_array('interval', $definition['filters'], true))
                <div>
                    <label for="interval" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Group by</label>
                    <select id="interval" name="interval" class="input">
                        @foreach (['day' => 'Day', 'month' => 'Month', 'year' => 'Year'] as $value => $label)
                            <option value="{{ $value }}" @selected($meta['interval'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @foreach ([
                'branch' => ['branches', 'branch_id', 'Branch', 'All branches'],
                'employee' => ['employees', 'employee_id', 'Employee', 'Everyone'],
                'customer' => ['customers', 'customer_id', 'Customer', 'Choose a customer'],
                'supplier' => ['suppliers', 'supplier_id', 'Supplier', 'All suppliers'],
                'category' => ['categories', 'category_id', 'Category', 'All categories'],
                'product' => ['products', 'product_id', 'Product', 'All products'],
            ] as $filter => [$optionKey, $field, $label, $placeholder])
                @continue(! in_array($filter, $definition['filters'], true))
                <div>
                    <label for="{{ $field }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}" class="input">
                        <option value="">{{ $placeholder }}</option>
                        @foreach ($filters[$optionKey] ?? [] as $option)
                            <option value="{{ $option->id }}" @selected((int) ($meta[$field] ?? 0) === $option->id)>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                <a href="{{ route('app.reports.show', $report['key']) }}" class="btn btn-ghost">Reset</a>

                @if (in_array('period', $definition['filters'], true))
                    <div class="ml-auto flex flex-wrap gap-2">
                        @foreach ([
                            'Today' => [now(), now()],
                            'This month' => [now()->startOfMonth(), now()],
                            'Last month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                            'This year' => [now()->startOfYear(), now()],
                        ] as $label => $range)
                            <a href="{{ route('app.reports.show', array_merge($query, [
                                    'report' => $report['key'],
                                    'from' => $range[0]->toDateString(),
                                    'to' => $range[1]->toDateString(),
                                ])) }}"
                               class="rounded-lg px-3 py-1.5 text-xs font-medium {{ $meta['from'] === $range[0]->toDateString() && $meta['to'] === $range[1]->toDateString()
                                    ? 'bg-brand-600 text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </form>
    </div>

    @if ($report['chart'] && $report['rows']->isNotEmpty())
        <div class="card mb-5 p-5">
            <div id="reportChart" class="min-h-[260px]"></div>
        </div>
    @endif

    {{-- ────────────────────────────── the table ───────────────────────────── --}}
    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ $report['name'] }}</h2>
                <p class="mt-0.5 text-xs text-slate-400">
                    @if (($meta['dated'] ?? true) === false)
                        As at {{ now()->format('d M Y, H:i') }}
                    @else
                        {{ \Illuminate\Support\Carbon::parse($meta['from'])->format('d M Y') }}
                        to {{ \Illuminate\Support\Carbon::parse($meta['to'])->format('d M Y') }}
                    @endif
                    @if ($meta['branch'])
                        · {{ $meta['branch']->name }}
                    @endif
                    · {{ number_format($report['rows']->count()) }} {{ Str::plural('row', $report['rows']->count()) }}
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        @foreach ($report['columns'] as $column)
                            <th class="px-5 py-3 font-medium {{ $align($column) }}">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($report['rows'] as $row)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            @foreach ($report['columns'] as $column)
                                @php
                                    $value = $row[$column['key']] ?? null;
                                    $negative = ($column['signed'] ?? false) && (float) $value < 0;
                                @endphp
                                <td class="px-5 py-2.5 {{ $align($column) }}
                                    {{ ($column['format'] ?? '') !== 'text' ? 'tabular-nums' : '' }}
                                    {{ ($column['emphasis'] ?? false) ? 'font-medium text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-300' }}
                                    {{ $negative ? '!text-rose-600 dark:!text-rose-400' : '' }}">
                                    {{ $render($value, $column) }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($report['columns']) }}" class="px-5 py-12 text-center text-slate-400">
                                Nothing in this period. That is an answer too.
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($report['totals'] !== null && $report['rows']->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold dark:border-slate-700 dark:bg-slate-800/50">
                            @foreach ($report['columns'] as $column)
                                <td class="px-5 py-3 {{ $align($column) }} {{ ($column['format'] ?? '') !== 'text' ? 'tabular-nums' : '' }} text-slate-900 dark:text-white">
                                    {{ $render($report['totals'][$column['key']] ?? null, $column) }}
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    @if ($report['chart'] && $report['rows']->isNotEmpty())
        @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const el = document.getElementById('reportChart');

                if (! el || typeof window.loadCharts !== 'function') {
                    return;
                }

                const labels = @json($report['chart']['labels']);
                const series = @json($report['chart']['series']);

                const options = (dark) => {
                    const base = window.chartDefaults(dark);

                    return {
                        ...base,
                        chart: { ...base.chart, type: series.length > 1 ? 'line' : 'bar', height: 260 },
                        series,
                        stroke: { curve: 'smooth', width: series.length > 1 ? 2.5 : 0 },
                        xaxis: {
                            categories: labels,
                            tickAmount: Math.min(labels.length, 8),
                            labels: { rotate: labels.length > 14 ? -45 : 0, hideOverlappingLabels: true },
                            axisBorder: { show: false },
                            axisTicks: { show: false },
                        },
                        yaxis: { labels: { formatter: (v) => Math.round(v).toLocaleString() } },
                        plotOptions: { bar: { columnWidth: labels.length > 45 ? '90%' : '55%', borderRadius: 3 } },
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
    @endif

</x-layouts.app>
