@php
    // Severity → the visual language used everywhere else in the console.
    $tones = [
        'danger' => [
            'card' => 'border-rose-200 dark:border-rose-500/30',
            'stripe' => 'bg-rose-500',
            'chip' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
            'icon' => 'text-rose-600 dark:text-rose-400',
            'label' => 'Needs action now',
        ],
        'warning' => [
            'card' => 'border-amber-200 dark:border-amber-500/30',
            'stripe' => 'bg-amber-500',
            'chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
            'icon' => 'text-amber-600 dark:text-amber-400',
            'label' => 'Coming up',
        ],
        'info' => [
            'card' => 'border-slate-200 dark:border-slate-700',
            'stripe' => 'bg-slate-400',
            'chip' => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
            'icon' => 'text-slate-500 dark:text-slate-400',
            'label' => 'For information',
        ],
    ];
@endphp

<x-layouts.admin title="System alerts">

    <x-flash />

    <div class="mb-5">
        <h2 class="text-xl font-bold text-slate-900 dark:text-white">System alerts</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            @if ($alerts === [])
                Everything is in order across every tenant.
            @else
                {{ count($alerts) }} {{ Str::plural('thing', count($alerts)) }} to review,
                affecting {{ $affected }} {{ Str::plural('record', $affected) }}.
                Nothing here is cached — fix a problem and it disappears on the next page load.
            @endif
        </p>
    </div>

    @if ($alerts === [])
        <div class="card px-6 py-16 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 dark:bg-emerald-500/10">
                <x-icon name="check-circle" class="h-7 w-7 text-emerald-600 dark:text-emerald-400" />
            </div>
            <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">Nothing needs attention</h3>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                No expired or lapsing subscriptions, no unassigned businesses, no unconfirmed payments and no failed
                background jobs.
            </p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($alerts as $alert)
                @php $tone = $tones[$alert['severity']] ?? $tones['info']; @endphp

                <div class="card relative overflow-hidden {{ $tone['card'] }}">
                    <span class="absolute inset-y-0 left-0 w-1 {{ $tone['stripe'] }}"></span>

                    <div class="p-5 pl-6">
                        <div class="flex flex-wrap items-start gap-3">
                            <x-icon :name="$alert['icon']" class="mt-0.5 h-5 w-5 shrink-0 {{ $tone['icon'] }}" />

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">
                                        {{ $alert['title'] }}
                                    </h3>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $tone['chip'] }}">
                                        {{ $alert['count'] }}
                                    </span>
                                    <span class="text-[11px] uppercase tracking-wide text-slate-400">
                                        {{ $tone['label'] }}
                                    </span>
                                </div>

                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $alert['message'] }}</p>

                                {{-- The affected rows, so the operator can act without hunting. --}}
                                @if ($alert['items'] !== [])
                                    <ul class="mt-3 flex flex-wrap gap-2">
                                        @foreach ($alert['items'] as $item)
                                            <li>
                                                @if ($item['url'])
                                                    <a href="{{ $item['url'] }}"
                                                       class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs hover:border-brand-300 hover:bg-brand-50 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-brand-500/40 dark:hover:bg-slate-700">
                                                        <span class="font-medium text-slate-800 dark:text-slate-200">{{ $item['label'] }}</span>
                                                        <span class="text-slate-400">· {{ $item['meta'] }}</span>
                                                    </a>
                                                @else
                                                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800">
                                                        <span class="font-medium text-slate-800 dark:text-slate-200">{{ $item['label'] }}</span>
                                                        <span class="text-slate-400">· {{ $item['meta'] }}</span>
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach

                                        @if ($alert['more'] > 0)
                                            <li class="inline-flex items-center px-1.5 py-1.5 text-xs text-slate-500">
                                                +{{ $alert['more'] }} more
                                            </li>
                                        @endif
                                    </ul>
                                @endif

                                {{-- A command the operator runs on the server. --}}
                                @if ($alert['hint'])
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <code class="rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs text-slate-100 dark:bg-slate-800">{{ $alert['hint'] }}</code>

                                        {{-- Status drift is the one alert repairable from the browser. --}}
                                        @if ($alert['key'] === 'status_drift')
                                            <form method="POST" action="{{ route('admin.notifications.reconcile') }}">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary py-1.5 text-xs">
                                                    <x-icon name="refresh" class="h-3.5 w-3.5" /> Reconcile now
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if ($alert['url'])
                                <a href="{{ $alert['url'] }}" class="btn btn-secondary shrink-0 text-xs">
                                    {{ $alert['action'] }}
                                    <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Why this list can be trusted — the reasoning belongs on the screen. --}}
    <div class="card mt-5 p-5">
        <div class="flex gap-3">
            <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
            <div class="text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                <p class="font-semibold text-slate-700 dark:text-slate-300">How these are worked out</p>
                <p class="mt-1">
                    Subscription state is <strong>derived from dates</strong>, never read from the stored
                    <code>status</code> column — that is why a stale column shows up here as its own alert instead of
                    hiding the tenants behind it. Each tenant is counted once, in its most serious category, so the
                    badge cannot double-count. Expiry windows come from your
                    <code>subscription.warning_days</code> setting rather than a fixed number of days.
                </p>
            </div>
        </div>
    </div>

</x-layouts.admin>
