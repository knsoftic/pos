<x-layouts.app title="Dashboard">

    <x-flash />

    @php
        $period = $data['period'];
        $presets = [
            'Today' => [now(), now()],
            'Last 7 days' => [now()->subDays(6), now()],
            'This month' => [now()->startOfMonth(), now()],
            'Last 30 days' => [now()->subDays(29), now()],
        ];
    @endphp

    {{-- ──────────────────────────── the greeting ─────────────────────────── --}}
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $business?->name }}</p>
            <h2 class="mt-0.5 text-2xl font-bold text-slate-900 dark:text-white">
                {{ now()->setTimezone(\App\Support\Format::timezone())->hour < 12 ? 'Good morning' : (now()->setTimezone(\App\Support\Format::timezone())->hour < 17 ? 'Good afternoon' : 'Good evening') }},
                {{ \Illuminate\Support\Str::of($user?->name ?? '')->explode(' ')->first() }}
            </h2>
        </div>

        {{-- Period filter (#12). Presets rather than a date picker: a dashboard
             is glanced at, and the four ranges below are the ones anybody
             actually asks for. --}}
        <div class="flex flex-wrap gap-2">
            @foreach ($presets as $label => $range)
                @php $active = $period['from'] === $range[0]->toDateString() && $period['to'] === $range[1]->toDateString(); @endphp
                <a href="{{ route('app.dashboard', ['from' => $range[0]->toDateString(), 'to' => $range[1]->toDateString()]) }}"
                   class="rounded-lg px-3 py-1.5 text-xs font-medium transition
                          {{ $active
                                ? 'bg-brand-600 text-white'
                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- ────────────────────────── quick actions (#123) ────────────────────── --}}
    @if ($data['actions'] !== [])
        <div class="mb-5 flex flex-wrap gap-2">
            @foreach ($data['actions'] as $action)
                <a href="{{ route($action['href']) }}"
                   class="btn {{ ($action['primary'] ?? false) ? 'btn-primary' : 'btn-secondary' }}">
                    <x-icon :name="$action['icon']" class="h-4 w-4" /> {{ $action['label'] }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- ─────────────────────────── getting started ───────────────────────── --}}
    @if ($data['setup'] !== [])
        <div class="card mb-5 p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Getting set up</h3>
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                This list disappears once the shop is trading — it is not a permanent fixture.
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($data['setup'] as $step)
                    <li class="flex items-center gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full
                                     {{ $step['done']
                                        ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400'
                                        : 'bg-slate-100 text-slate-400 dark:bg-slate-800' }}">
                            <x-icon :name="$step['done'] ? 'check' : 'minus'" class="h-3.5 w-3.5" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="text-sm font-medium {{ $step['done'] ? 'text-slate-400 line-through dark:text-slate-500' : 'text-slate-800 dark:text-slate-200' }}">
                                {{ $step['label'] }}
                            </span>
                            <span class="ml-2 text-xs text-slate-400">{{ $step['meta'] }}</span>
                        </span>

                        @if (! $step['done'] && $step['href'])
                            <a href="{{ $step['href'] }}" class="btn btn-ghost !px-3 !py-1.5 text-xs">Do it</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ─────────────────────────────── the cards ─────────────────────────── --}}
    @if ($data['cards'] !== [])
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($data['cards'] as $card)
                <a href="{{ $card['href'] }}" class="card group p-5 transition hover:shadow-md">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>

                            @php
                                $negative = ($card['signed'] ?? false) && (float) $card['value'] < 0;
                                $rendered = $card['format'] === 'money'
                                    ? \App\Support\Format::money($card['value'])
                                    : number_format((float) $card['value']);
                            @endphp

                            <p class="mt-2 truncate text-2xl font-bold tabular-nums
                                      {{ $negative ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                {{ $rendered }}
                            </p>
                            <p class="mt-1 truncate text-xs text-slate-400">{{ $card['meta'] }}</p>
                        </div>

                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $card['tint'] }}">
                            <x-icon :name="$card['icon']" class="h-5 w-5" />
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- ─────────────────────────────── the chart ─────────────────────── --}}
        <div class="space-y-5 lg:col-span-2">
            @if ($data['chart'])
                <div class="card p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-semibold text-slate-900 dark:text-white">How trading is going</h3>
                        <p class="text-xs text-slate-400">
                            {{ \App\Support\Format::date($period['from']) }} – {{ \App\Support\Format::date($period['to']) }}
                        </p>
                    </div>
                    <div id="dashboardChart" class="mt-2 min-h-[260px]"></div>
                </div>
            @endif

            {{-- ──────────────────────── recent activity (#124) ───────────── --}}
            <div class="card overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h3 class="font-semibold text-slate-900 dark:text-white">What just happened</h3>
                </div>

                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($data['activity'] as $item)
                        <div class="flex items-start gap-3 px-5 py-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $item['tint'] }}">
                                <x-icon :name="$item['icon']" class="h-4 w-4" />
                            </span>

                            <div class="min-w-0 flex-1">
                                @if ($item['href'])
                                    <a href="{{ $item['href'] }}" class="text-sm font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                        {{ $item['title'] }}
                                    </a>
                                @else
                                    <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $item['title'] }}</p>
                                @endif
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $item['body'] }}</p>
                            </div>

                            <p class="shrink-0 text-xs text-slate-400">{{ \App\Support\Format::local($item['at'])?->diffForHumans(short: true) }}</p>
                        </div>
                    @empty
                        <p class="px-5 py-10 text-center text-sm text-slate-400">
                            Nothing yet. It fills up as soon as the shop starts trading.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ──────────────────────────────── the team ─────────────────────── --}}
        <div class="space-y-5">
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Your team</h3>
                    @can(\App\Support\PermissionRegistry::EMPLOYEES_VIEW)
                        <a href="{{ route('app.employees.index') }}" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">All</a>
                    @endcan
                </div>

                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($team as $member)
                        <div class="flex items-center gap-3 px-5 py-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">
                                {{ strtoupper(mb_substr($member->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $member->name }}</p>
                                <p class="truncate text-xs text-slate-400">{{ $member->email }}</p>
                            </div>
                            <span class="badge-slate shrink-0">{{ $member->is_business_owner ? 'Owner' : ($member->role?->name ?? 'No role') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card p-5">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
                        <x-icon name="shield" class="h-4 w-4" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">Your data is yours alone</p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                            Every query in this workspace is scoped to {{ $business?->name }}. No other business can
                            reach a single row of it, and nor can you reach theirs.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($data['chart'])
        @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const el = document.getElementById('dashboardChart');

                if (! el || typeof window.loadCharts !== 'function') {
                    return;
                }

                const labels = @json($data['chart']['labels']);
                const series = @json($data['chart']['series']);

                const options = (dark) => {
                    const base = window.chartDefaults(dark);

                    return {
                        ...base,
                        chart: { ...base.chart, type: 'area', height: 260 },
                        series,
                        stroke: { curve: 'smooth', width: 2.5 },
                        fill: {
                            type: 'gradient',
                            gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 95] },
                        },
                        xaxis: {
                            categories: labels,
                            tickAmount: Math.min(labels.length, 7),
                            labels: {
                                rotate: labels.length > 14 ? -45 : 0,
                                hideOverlappingLabels: true,
                                formatter: (v) => (v || '').slice(5),
                            },
                            axisBorder: { show: false },
                            axisTicks: { show: false },
                        },
                        yaxis: { labels: { formatter: (v) => Math.round(v).toLocaleString() } },
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
