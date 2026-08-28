@php
    $currency = config('subscription.currency_symbol');
    $decimals = (int) config('subscription.currency_decimals');
    $enabled = array_flip($enabledFeatures);
@endphp

<x-layouts.app title="Billing & plan">

    <x-flash />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

        {{-- ============================= CURRENT PLAN ============================= --}}
        <div class="space-y-5 lg:col-span-2">

            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Your plan</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <h3 class="text-2xl font-bold text-slate-900 dark:text-white">{{ $plan?->name ?? 'No plan' }}</h3>
                            @if ($status)
                                <span class="{{ $status->badgeClass() }}">{{ $status->label() }}</span>
                            @endif
                            @if ($isInGrace)
                                <span class="badge-amber">Grace period</span>
                            @endif
                        </div>
                        @if ($plan?->description)
                            <p class="mt-1 max-w-prose text-sm text-slate-500 dark:text-slate-400">{{ $plan->description }}</p>
                        @endif
                    </div>

                    <a href="{{ route('app.billing.plans') }}" class="btn btn-primary">
                        <x-icon name="arrow-right" class="h-4 w-4" /> Compare plans
                    </a>
                </div>

                @if ($subscription)
                    <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 dark:border-slate-800 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">Billed</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $subscription->formattedPrice() }}</dd>
                            <dd class="text-xs text-slate-400">{{ $subscription->billing_cycle->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">Started</dt>
                            <dd class="mt-0.5 tabular-nums text-slate-700 dark:text-slate-300">{{ $subscription->starts_at?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">
                                {{ $subscription->neverExpires() ? 'Expiry' : 'Renews on' }}
                            </dt>
                            <dd class="mt-0.5 tabular-nums text-slate-700 dark:text-slate-300">
                                {{ $subscription->neverExpires() ? 'Never' : ($subscription->ends_at?->format('d M Y') ?? '—') }}
                            </dd>
                            @if ($daysRemaining !== null)
                                <dd @class([
                                    'text-xs',
                                    'text-rose-600 dark:text-rose-400' => $daysRemaining <= 0,
                                    'text-amber-600 dark:text-amber-400' => $daysRemaining > 0 && $warningThreshold !== null,
                                    'text-slate-400' => $daysRemaining > 0 && $warningThreshold === null,
                                ])>
                                    {{ $daysRemaining <= 0 ? 'Lapsed' : $daysRemaining.' day(s) left' }}
                                </dd>
                            @endif
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">Paid to date</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ $currency }}{{ number_format((float) $amountPaid, $decimals) }}
                            </dd>
                            <dd class="text-xs text-slate-400">This period</dd>
                        </div>
                    </dl>

                    @if ($onTrial && $subscription->trial_ends_at)
                        <p class="mt-4 rounded-xl bg-brand-50 px-3 py-2 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
                            You are on a free trial until <strong>{{ $subscription->trial_ends_at->format('d M Y') }}</strong>.
                            Everything you enter now is kept when you move onto a paid plan.
                        </p>
                    @endif

                    @if ($isInGrace)
                        <p class="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                            Your plan ran out on <strong>{{ $subscription->ends_at?->format('d M Y') }}</strong>. You have
                            <strong>{{ $graceDaysRemaining }}</strong> grace day(s) left before access changes.
                        </p>
                    @endif
                @else
                    <p class="mt-4 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
                        Your account is not on a plan yet, so the rest of the app is locked. Nothing you have entered is
                        lost — it comes back as soon as a plan is active.
                    </p>
                @endif

                {{-- Read-only by design (#82): pretending to take a card payment
                     that nothing processes would be worse than saying so. --}}
                <div class="mt-5 flex items-start gap-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
                    <p>
                        Renewals and plan changes are handled by our team in this release — there is no self-serve
                        checkout yet. Send us a message and we will apply it, usually the same day.
                    </p>
                </div>
            </div>

            {{-- ------------------------------- features ------------------------------- --}}
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white">What your plan includes</h3>
                        <p class="mt-0.5 text-xs text-slate-400">
                            Greyed rows are available on a higher plan.
                        </p>
                    </div>
                    <span class="badge-slate">{{ count($enabledFeatures) }} enabled</span>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                    @foreach ($featureGroups as $group => $features)
                        <div>
                            <h4 class="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                {{ $featureGroupLabels[$group] ?? ucfirst($group) }}
                            </h4>
                            <ul class="space-y-1">
                                @foreach ($features as $feature)
                                    @php $on = isset($enabled[$feature->code]); @endphp
                                    <li class="flex items-start gap-2 text-sm">
                                        <x-icon :name="$on ? 'check' : 'minus'" @class([
                                            'mt-0.5 h-4 w-4 shrink-0',
                                            'text-emerald-600 dark:text-emerald-400' => $on,
                                            'text-slate-300 dark:text-slate-600' => ! $on,
                                        ]) />
                                        <span @class([
                                            'text-slate-700 dark:text-slate-300' => $on,
                                            'text-slate-400' => ! $on,
                                        ]) @if ($feature->description) title="{{ $feature->description }}" @endif>
                                            {{ $feature->name }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ------------------------------- payments ------------------------------- --}}
            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">Payment history</h3>
                <p class="mt-0.5 text-xs text-slate-400">The last {{ $payments->count() }} payment(s) recorded on your account.</p>

                <div class="mt-4 table-wrap">
                    <table class="w-full min-w-[480px] text-sm">
                        <thead>
                            <tr>
                                <th class="th text-left">Date</th>
                                <th class="th text-right">Amount</th>
                                <th class="th text-left">Method</th>
                                <th class="th text-left">Status</th>
                                <th class="th text-left">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="td whitespace-nowrap text-slate-600 dark:text-slate-300">
                                        {{ $payment->paid_at?->format('d M Y') ?? $payment->created_at?->format('d M Y') }}
                                    </td>
                                    <td class="td text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ $payment->formattedAmount() }}</td>
                                    <td class="td text-slate-600 dark:text-slate-300">{{ $payment->methodLabel() }}</td>
                                    <td class="td"><span class="{{ $payment->status->badgeClass() }}">{{ $payment->status->label() }}</span></td>
                                    <td class="td text-slate-500 dark:text-slate-400">{{ $payment->reference ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="td py-6 text-center text-slate-400" colspan="5">
                                        Nothing recorded yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- -------------------------------- history -------------------------------- --}}
            @if ($history->count() > 1)
                <div class="card p-5">
                    <div class="flex items-center gap-2">
                        <x-icon name="history" class="h-4 w-4 text-slate-400" />
                        <h3 class="font-semibold text-slate-900 dark:text-white">Plan history</h3>
                    </div>

                    <ul class="mt-4 space-y-2">
                        @foreach ($history as $row)
                            <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-slate-900 dark:text-white">{{ $row->plan?->name ?? 'Plan' }}</span>
                                    <span class="text-xs text-slate-400">{{ $row->billing_cycle->label() }}</span>
                                    @if ($row->isCurrent())
                                        <span class="badge-brand">Current</span>
                                    @endif
                                </span>
                                <span class="text-xs tabular-nums text-slate-400">
                                    {{ $row->starts_at?->format('d M Y') }} –
                                    {{ $row->ends_at?->format('d M Y') ?? 'never' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- ================================ USAGE ================================ --}}
        <div class="space-y-5">
            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">Usage this period</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Counted live from your data — monthly quotas reset at the start of each month.
                </p>

                <div class="mt-4 space-y-3">
                    @forelse ($meters as $meter)
                        <x-meter :meter="$meter" />
                    @empty
                        <p class="text-sm text-slate-400">No quotas apply without an active plan.</p>
                    @endforelse
                </div>
            </div>

            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">Need a change?</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    More users, another branch, a bigger product catalogue — tell us what you have outgrown and we will
                    move you onto the right plan.
                </p>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('app.billing.plans') }}" class="btn btn-secondary w-full justify-center">
                        <x-icon name="sliders" class="h-4 w-4" /> Compare all plans
                    </a>
                    @if ($business->email)
                        <a href="mailto:{{ $business->email }}" class="btn btn-ghost w-full justify-center">
                            <x-icon name="mail" class="h-4 w-4" /> {{ $business->email }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

</x-layouts.app>
