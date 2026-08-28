<x-layouts.admin title="Dashboard">

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Total Businesses', 'value' => $stats['businesses_total'],     'icon' => 'building',    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10'],
                ['label' => 'Active',            'value' => $stats['businesses_active'],    'icon' => 'check-circle','tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10'],
                ['label' => 'Suspended',         'value' => $stats['businesses_suspended'], 'icon' => 'alert',       'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'],
                ['label' => 'Total Users',       'value' => $stats['users_total'],          'icon' => 'customers',   'tint' => 'text-violet-600 bg-violet-50 dark:bg-violet-500/10'],
            ];
        @endphp
        @foreach ($cards as $c)
            <div class="card p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $c['label'] }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{{ number_format($c['value']) }}</p>
                    </div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl {{ $c['tint'] }}">
                        <x-icon :name="$c['icon']" class="h-5 w-5" />
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Business growth --}}
    <div class="card mt-6 p-5">
        <div class="flex items-start justify-between">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">Business growth</h3>
                <p class="mt-0.5 text-xs text-slate-400">New sign-ups per month · last {{ count($growth['labels']) }} months</p>
            </div>
            <span class="badge-slate">{{ number_format(array_sum($growth['series'])) }} total</span>
        </div>
        <div id="growthChart" class="mt-2 -mx-2"></div>
    </div>

    {{-- Recent businesses --}}
    <div class="card mt-6 overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h3 class="font-semibold text-slate-900 dark:text-white">Recent businesses</h3>
            <a href="{{ route('admin.businesses.index') }}" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">All businesses &rarr;</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Business</th>
                        <th class="px-5 py-3 font-medium">Email</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($recentBusinesses as $b)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10">
                                        <x-icon name="building" class="h-4 w-4" />
                                    </span>
                                    <div>
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $b->name }}</p>
                                        <p class="text-xs text-slate-400">{{ $b->slug }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $b->email ?? '—' }}</td>
                            <td class="px-5 py-3">
                                @php
                                    $badge = match ($b->status) {
                                        \App\Models\Business::STATUS_ACTIVE => 'badge-green',
                                        \App\Models\Business::STATUS_SUSPENDED => 'badge-amber',
                                        default => 'badge-slate',
                                    };
                                @endphp
                                <span class="{{ $badge }}">{{ ucfirst($b->status) }}</span>
                            </td>
                            <td class="px-5 py-3 text-slate-500">{{ $b->created_at?->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-slate-400">No businesses yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- NOTE: @push must sit INSIDE the layout component. Anything after the
         closing tag renders after the layout's @stack has already been output,
         so the script would silently never appear. --}}
    @push('scripts')
    <script>
        // app.js is loaded as an ES module (deferred), so window.loadCharts()
        // only exists once module evaluation has finished.
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('growthChart');
            if (! el || typeof window.loadCharts !== 'function') {
                return;
            }

            const labels = @json($growth['labels']);
            const series = @json($growth['series']);

            // Sign-up counts are whole numbers, so force integer ticks —
            // otherwise Apex picks fractional steps and the rounded labels
            // repeat (0, 1, 1, 2, 2).
            const peak = Math.max(1, ...series);

            const options = (dark) => {
                const base = window.chartDefaults(dark);

                return {
                    ...base,
                    chart: { ...base.chart, type: 'area', height: 260 },
                    series: [{ name: 'New businesses', data: series }],
                    xaxis: { categories: labels, axisBorder: { show: false }, axisTicks: { show: false } },
                    yaxis: {
                        min: 0,
                        max: peak,
                        tickAmount: Math.min(peak, 5),
                        labels: { formatter: (v) => Math.round(v) },
                    },
                    stroke: { curve: 'smooth', width: 2.5 },
                    fill: {
                        type: 'gradient',
                        gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 95] },
                    },
                };
            };

            // Charts live in a lazily-fetched chunk (see resources/js/app.js).
            window.loadCharts().then((ApexCharts) => {
                const chart = new ApexCharts(el, options(document.documentElement.classList.contains('dark')));
                chart.render();

                // Re-theme when the user flips light/dark.
                window.addEventListener('theme-changed', (e) => {
                    chart.updateOptions(options(e.detail.dark), false, false);
                });
            });
        });
    </script>
    @endpush

</x-layouts.admin>
