@props([
    'meter',            // one row from PlanLimitService::meter()
    'compact' => false, // dense variant for the sidebar
])

{{--
    A single usage meter — "350 / 500" plus a bar (#78).

    The bar colour is NOT decided here: PlanLimitService::meter() returns
    `bar_class`, so the amber/rose thresholds are defined once in PHP and every
    meter in the product agrees on when a quota is "nearly full".
--}}
<div {{ $attributes->merge(['class' => $compact ? '' : 'py-1']) }}>
    <div class="flex items-baseline justify-between gap-2">
        <span @class([
            'truncate font-medium',
            'text-[11px] text-slate-500 dark:text-slate-400' => $compact,
            'text-sm text-slate-700 dark:text-slate-300' => ! $compact,
        ])>
            {{ $meter['name'] }}
            @if ($meter['is_monthly'])
                <span class="font-normal text-slate-400">/ month</span>
            @endif
        </span>

        <span @class([
            'shrink-0 tabular-nums',
            'text-[11px]' => $compact,
            'text-xs' => ! $compact,
            'font-semibold text-rose-600 dark:text-rose-400' => $meter['exhausted'],
            'font-semibold text-amber-600 dark:text-amber-400' => $meter['nearly_exhausted'],
            'text-slate-500 dark:text-slate-400' => ! $meter['exhausted'] && ! $meter['nearly_exhausted'],
        ])>
            {{ $meter['label'] }}
        </span>
    </div>

    <div class="meter {{ $compact ? 'mt-1 !h-1.5' : 'mt-1.5' }}">
        {{-- Unlimited quotas have no meaningful fill, so show a faint full bar
             rather than an empty track that reads as "nothing left". --}}
        <div class="meter-bar {{ $meter['bar_class'] }} {{ $meter['unlimited'] ? 'opacity-25' : '' }}"
             style="width: {{ $meter['unlimited'] ? 100 : $meter['percent'] }}%"></div>
    </div>

    @unless ($compact)
        @if ($meter['exhausted'])
            <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                Limit reached — upgrade to add more {{ $meter['unit'] ?? 'items' }}.
            </p>
        @elseif ($meter['nearly_exhausted'])
            <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                Only {{ number_format((float) $meter['remaining']) }} left.
            </p>
        @endif
    @endunless
</div>
