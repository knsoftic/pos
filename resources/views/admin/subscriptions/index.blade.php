@php
    $currency = config('subscription.currency_symbol');
    $decimals = (int) config('subscription.currency_decimals');

    // Filter windows come from the same config that drives the warning banners,
    // so what an operator can filter on always matches what tenants are told.
    $windows = collect((array) config('subscription.warning_days'))
        ->map(fn ($d) => (int) $d)
        ->push(30)
        ->unique()
        ->sort()
        ->values();

    $hasFilters = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty();
@endphp

<x-layouts.admin title="Subscriptions">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Subscriptions</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Current period for every tenant. Superseded periods are excluded — open a business to see its full
                history.
            </p>
        </div>
        <a href="{{ route('admin.businesses.create') }}" class="btn btn-primary">
            <x-icon name="plus" class="h-4 w-4" /> New business
        </a>
    </div>

    {{-- ================================ REVENUE ================================ --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @php
            $cards = [
                ['label' => 'Collected all-time', 'value' => $revenue['collected'], 'icon' => 'check-circle', 'tone' => 'text-emerald-600 dark:text-emerald-400', 'note' => 'Settled payments only.'],
                ['label' => 'Collected this month', 'value' => $revenue['this_month'], 'icon' => 'trending-up', 'tone' => 'text-brand-600 dark:text-brand-400', 'note' => 'By date received, not by period.'],
                ['label' => 'Awaiting settlement', 'value' => $revenue['pending'], 'icon' => 'clock', 'tone' => 'text-amber-600 dark:text-amber-400', 'note' => 'Recorded as pending — not yet revenue.'],
            ];
        @endphp

        @foreach ($cards as $card)
            <div class="card p-4">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $card['label'] }}</p>
                    <x-icon :name="$card['icon']" class="h-4 w-4 {{ $card['tone'] }}" />
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                    {{ $currency }}{{ number_format((float) $card['value'], $decimals) }}
                </p>
                <p class="mt-0.5 text-xs text-slate-400">{{ $card['note'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- ============================= EXPIRING SOON ============================= --}}
    @if ($expiringSoon->isNotEmpty())
        <div class="card mb-5 border-amber-200 p-4 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                        {{ $expiringSoon->count() }} subscription(s) expire within {{ max((array) config('subscription.warning_days')) ?: 7 }} days
                    </p>
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($expiringSoon as $row)
                            <li>
                                <a href="{{ route('admin.businesses.show', $row->business) }}"
                                   class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-2 py-1 text-xs text-amber-800 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20">
                                    <span class="font-medium">{{ $row->business?->name ?? 'Unknown' }}</span>
                                    <span class="tabular-nums">{{ $row->daysRemaining() }}d</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- ================================ FILTERS ================================ --}}
    <form method="GET" action="{{ route('admin.subscriptions.index') }}" class="card mb-5 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[10rem]">
                <label class="label" for="f_status">Stored status</label>
                <select id="f_status" name="status" class="input">
                    <option value="">Any</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="min-w-[10rem]">
                <label class="label" for="f_plan">Plan</label>
                <select id="f_plan" name="plan" class="input">
                    <option value="">Any</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->slug }}" @selected($filters['plan'] === $plan->slug)>{{ $plan->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="min-w-[10rem]">
                <label class="label" for="f_expiring">Expiring within</label>
                <select id="f_expiring" name="expiring" class="input">
                    <option value="">Any time</option>
                    @foreach ($windows as $days)
                        <option value="{{ $days }}" @selected($filters['expiring'] === (string) $days)>{{ $days }} day(s)</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-4 w-4" /> Filter
            </button>

            @if ($hasFilters)
                <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>

        {{-- The stored column and the effective status can disagree: a row still
             saying "active" is shown as Expired once its date has passed. Saying so
             beats an operator wondering why the filter and the badge differ. --}}
        @if ($filters['status'] !== '')
            <p class="mt-3 flex items-start gap-1.5 text-xs text-slate-400">
                <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Filtering the <em>stored</em> status. The badge shows the effective status, which is recomputed from the
                dates — so an "active" row past its end date appears as expired.
            </p>
        @endif
    </form>

    {{-- ================================= TABLE ================================= --}}
    <div class="card overflow-hidden">
        <div class="table-wrap">
            <table class="w-full min-w-[880px] text-sm">
                <thead>
                    <tr>
                        <th class="th text-left">Business</th>
                        <th class="th text-left">Plan</th>
                        <th class="th text-left">Cycle</th>
                        <th class="th text-right">Price</th>
                        <th class="th text-left">Status</th>
                        <th class="th text-left">Period</th>
                        <th class="th text-right">Remaining</th>
                        <th class="th text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $subscription)
                        @php
                            $status = $subscription->effectiveStatus();
                            $days = $subscription->daysRemaining();
                            $threshold = $subscription->expiryWarningThreshold();
                        @endphp
                        <tr>
                            <td class="td">
                                @if ($subscription->business)
                                    <a href="{{ route('admin.businesses.show', $subscription->business) }}"
                                       class="font-medium text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">
                                        {{ $subscription->business->name }}
                                    </a>
                                    <p class="text-xs text-slate-400">
                                        {{ $subscription->business->slug }}
                                        @unless ($subscription->business->isActive())
                                            · <span class="font-medium text-rose-500">{{ $subscription->business->status }}</span>
                                        @endunless
                                    </p>
                                @else
                                    <span class="text-slate-400">Business removed</span>
                                @endif
                            </td>

                            <td class="td text-slate-600 dark:text-slate-300">{{ $subscription->plan?->name ?? '—' }}</td>
                            <td class="td text-slate-500 dark:text-slate-400">{{ $subscription->billing_cycle->label() }}</td>
                            <td class="td text-right font-medium tabular-nums text-slate-900 dark:text-white">{{ $subscription->formattedPrice() }}</td>

                            <td class="td">
                                <span class="{{ $status->badgeClass() }}">{{ $status->label() }}</span>
                                @if ($subscription->isInGrace())
                                    <span class="badge-amber ml-1">Grace</span>
                                @endif
                            </td>

                            <td class="td whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                                {{ $subscription->starts_at?->format('d M y') ?? '—' }} →
                                {{ $subscription->neverExpires() ? 'never' : ($subscription->ends_at?->format('d M y') ?? '—') }}
                            </td>

                            <td class="td text-right tabular-nums">
                                @if ($days === null)
                                    <span class="text-slate-400">—</span>
                                @else
                                    <span @class([
                                        'font-medium',
                                        'text-rose-600 dark:text-rose-400' => $days <= 0,
                                        'text-amber-600 dark:text-amber-400' => $days > 0 && $threshold !== null,
                                        'text-slate-500 dark:text-slate-400' => $days > 0 && $threshold === null,
                                    ])>{{ $days }}d</span>
                                @endif
                            </td>

                            <td class="td text-right">
                                @if ($subscription->business)
                                    <a href="{{ route('admin.businesses.show', $subscription->business) }}"
                                       class="btn btn-ghost !px-2 !py-1" title="Manage">
                                        <x-icon name="arrow-right" class="h-4 w-4" />
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="td py-10 text-center" colspan="8">
                                <x-icon name="credit-card" class="mx-auto h-8 w-8 text-slate-300 dark:text-slate-600" />
                                <p class="mt-2 text-slate-500 dark:text-slate-400">
                                    {{ $hasFilters ? 'No subscriptions match those filters.' : 'No subscriptions yet.' }}
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($subscriptions->hasPages())
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">
                {{ $subscriptions->links() }}
            </div>
        @endif
    </div>

</x-layouts.admin>
