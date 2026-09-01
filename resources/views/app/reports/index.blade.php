<x-layouts.app title="Reports">

    <x-flash />

    <div class="card mb-5 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">What the numbers say</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Every figure here counts <strong>completed</strong> sales only and is <strong>net of returns</strong>.
                    A held sale has not happened and a voided one has been undone — neither belongs in a day's takings.
                </p>
            </div>

            @can(\App\Support\PermissionRegistry::REPORTS_VIEW_PROFIT)
                @if (Route::has('app.reports.profit-loss'))
                    <a href="{{ route('app.reports.profit-loss') }}" class="btn btn-primary">
                        <x-icon name="trending-up" class="h-4 w-4" /> Profit &amp; Loss statement
                    </a>
                @endif
            @endcan
        </div>
    </div>

    @php
        $tints = [
            'sales' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
            'profit' => 'text-violet-600 bg-violet-50 dark:bg-violet-500/10',
            'inventory' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10',
            'purchases' => 'text-sky-600 bg-sky-50 dark:bg-sky-500/10',
            'customers' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
            'expenses' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10',
        ];
        $icons = [
            'sales' => 'sales',
            'profit' => 'trending-up',
            'inventory' => 'inventory',
            'purchases' => 'purchases',
            'customers' => 'customers',
            'expenses' => 'expenses',
        ];
    @endphp

    @forelse ($groups as $group => $reports)
        <div class="mb-6">
            <div class="mb-3 flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $tints[$group] ?? '' }}">
                    <x-icon :name="$icons[$group] ?? 'reports'" class="h-4 w-4" />
                </span>
                <h3 class="font-semibold text-slate-900 dark:text-white">{{ $groupLabels[$group] ?? Str::headline($group) }}</h3>
                <span class="text-xs text-slate-400">{{ count($reports) }}</span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($reports as $key => $report)
                    <a href="{{ route('app.reports.show', $key) }}"
                       class="card group p-4 transition hover:border-brand-300 hover:shadow-md dark:hover:border-brand-700">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">
                                    {{ $report['name'] }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $report['description'] }}</p>
                            </div>
                            <x-icon name="arrow-right" class="h-4 w-4 shrink-0 text-slate-300 group-hover:text-brand-600 dark:text-slate-600" />
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @empty
        <div class="card p-10 text-center">
            <p class="text-slate-500 dark:text-slate-400">
                No reports are available on this plan, or your role does not include them.
            </p>
        </div>
    @endforelse

</x-layouts.app>
