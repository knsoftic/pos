{{--
    Side-by-side plan comparison (#84).

    Reads the same three-level truth the resolver uses, and says so on screen: a
    quota cell shows the plan's own value, or — when the plan has no pivot row —
    the registry default it inherits, in grey. Without that distinction an
    operator cannot tell "this plan gives 10" from "nobody configured this".
--}}
<x-layouts.admin title="Plan comparison">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ $plans->count() }} plan(s) · grey values are inherited system defaults, not configured on the plan.
        </p>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.plans.index') }}" class="btn btn-secondary">
                <x-icon name="arrow-left" class="h-4 w-4" /> Plans
            </a>
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary">
                <x-icon name="plus" class="h-4 w-4" /> New plan
            </a>
        </div>
    </div>

    @if ($plans->isEmpty())
        <div class="card p-10 text-center">
            <x-icon name="sliders" class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" />
            <p class="mt-3 font-medium text-slate-700 dark:text-slate-300">Nothing to compare yet</p>
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary mt-4 inline-flex">
                <x-icon name="plus" class="h-4 w-4" /> Create a plan
            </a>
        </div>
    @else
        <div class="table-wrap">
            <table class="w-full min-w-[720px] text-sm">
                <thead>
                    <tr>
                        <th class="th sticky left-0 z-10 bg-slate-50 text-left dark:bg-slate-800/60">Plan</th>
                        @foreach ($plans as $plan)
                            <th class="th text-center">
                                <a href="{{ route('admin.plans.edit', $plan) }}" class="hover:underline">{{ $plan->name }}</a>
                                @unless ($plan->is_active)
                                    <span class="block text-[10px] font-normal normal-case text-slate-400">inactive</span>
                                @endunless
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    {{-- ---------------------------------------------- prices --}}
                    <tr>
                        <td class="td sticky left-0 z-10 bg-white font-medium dark:bg-slate-900">Price</td>
                        @foreach ($plans as $plan)
                            <td class="td text-center">
                                @forelse ($plan->activePrices() as $price)
                                    <div class="tabular-nums">
                                        <span class="font-semibold text-slate-900 dark:text-white">{{ $price->formatted() }}</span>
                                        <span class="text-xs text-slate-400">{{ $price->periodLabel() }}</span>
                                    </div>
                                @empty
                                    <span class="text-xs text-amber-600 dark:text-amber-400">no price</span>
                                @endforelse
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        <td class="td sticky left-0 z-10 bg-white font-medium dark:bg-slate-900">Trial / grace</td>
                        @foreach ($plans as $plan)
                            <td class="td text-center tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $plan->trialDays() }}d / {{ $plan->graceDays() }}d
                            </td>
                        @endforeach
                    </tr>

                    {{-- --------------------------------------------- features --}}
                    @foreach ($features as $group => $groupFeatures)
                        <tr>
                            <td class="td sticky left-0 z-10 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"
                                colspan="{{ $plans->count() + 1 }}">
                                {{ $featureGroupLabels[$group] ?? ucfirst($group) }}
                            </td>
                        </tr>
                        @foreach ($groupFeatures as $feature)
                            <tr>
                                <td class="td sticky left-0 z-10 bg-white dark:bg-slate-900" title="{{ $feature->code }}">
                                    {{ $feature->name }}
                                </td>
                                @foreach ($plans as $plan)
                                    @php $enabled = $featureMap[$plan->id][$feature->id] ?? null; @endphp
                                    <td class="td text-center">
                                        @if ($enabled === true)
                                            <x-icon name="check" class="mx-auto h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                        @elseif ($enabled === false)
                                            <x-icon name="x" class="mx-auto h-4 w-4 text-slate-300 dark:text-slate-600" />
                                        @else
                                            <span class="text-slate-300 dark:text-slate-600" title="Not configured on this plan">–</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach

                    {{-- ----------------------------------------------- quotas --}}
                    @foreach ($limits as $group => $groupLimits)
                        <tr>
                            <td class="td sticky left-0 z-10 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"
                                colspan="{{ $plans->count() + 1 }}">
                                {{ $limitGroupLabels[$group] ?? ucfirst($group) }}
                            </td>
                        </tr>
                        @foreach ($groupLimits as $limit)
                            <tr>
                                <td class="td sticky left-0 z-10 bg-white dark:bg-slate-900" title="{{ $limit->code }}">
                                    {{ $limit->name }}
                                    @if ($limit->is_monthly)
                                        <span class="text-xs text-slate-400">/ month</span>
                                    @endif
                                </td>
                                @foreach ($plans as $plan)
                                    @php
                                        $planQuotas = $limitMap[$plan->id] ?? [];
                                        $configured = array_key_exists($limit->id, $planQuotas);
                                        $value = $configured ? $planQuotas[$limit->id] : $limit->defaultValue();
                                    @endphp
                                    <td @class([
                                        'td text-center tabular-nums',
                                        'text-slate-900 dark:text-white' => $configured,
                                        'text-slate-400' => ! $configured,
                                    ])>
                                        @if ($value === null)
                                            <span title="{{ $configured ? 'Unlimited' : 'Inherited: unlimited' }}">Unlimited</span>
                                        @elseif ((int) $value === 0)
                                            <span class="text-rose-500 dark:text-rose-400" title="{{ $configured ? 'Not allowed on this plan' : 'Inherited default: not allowed' }}">0</span>
                                        @else
                                            <span title="{{ $configured ? 'Set on this plan' : 'Inherited system default' }}">{{ number_format((float) $value) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</x-layouts.admin>
