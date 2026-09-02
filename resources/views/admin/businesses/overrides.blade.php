@php
    $featureOverrideCount = $featureOverrides->count();
    $limitOverrideCount = $limitOverrides->count();
@endphp

<x-layouts.admin :title="'Overrides · '.$business->name">

    <x-flash />

    {{-- ================================ HEADER ================================ --}}
    <div class="mb-5">
        <a href="{{ route('admin.businesses.show', $business) }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $business->name }}
        </a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">Overrides</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Per-tenant exceptions that sit <em>above</em> the plan. Use them for one negotiated customer, not
                    as a substitute for a plan — a plan change reaches everyone, an override reaches nobody else.
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($featureOverrideCount + $limitOverrideCount > 0)
                    <span class="badge-brand">{{ $featureOverrideCount }} feature · {{ $limitOverrideCount }} quota</span>
                @else
                    <span class="badge-slate">Fully inherited</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Overrides are resolved before the plan, but AFTER "is there a subscription
         at all" — so without one, granting a feature here changes nothing. --}}
    @if ($subscription === null)
        <div class="card mb-5 border-rose-200 p-4 dark:border-rose-500/30">
            <div class="flex items-start gap-3">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-rose-500" />
                <div class="text-sm">
                    <p class="font-medium text-slate-800 dark:text-slate-200">This tenant has no subscription.</p>
                    <p class="mt-0.5 text-slate-500 dark:text-slate-400">
                        Access is refused before overrides are even consulted, so anything set here stays dormant until
                        a plan is assigned. Overrides are still saved.
                    </p>
                </div>
            </div>
        </div>
    @else
        <div class="card mb-5 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <div class="flex items-center gap-2">
                    <x-icon name="credit-card" class="h-4 w-4 text-brand-600 dark:text-brand-400" />
                    <span class="text-slate-500 dark:text-slate-400">Inheriting from</span>
                    <span class="font-semibold text-slate-900 dark:text-white">{{ $subscription->plan?->name ?? 'Deleted plan' }}</span>
                    <span class="{{ $subscription->effectiveStatus()->badgeClass() }}">{{ $subscription->effectiveStatus()->label() }}</span>
                </div>
                @if ($subscription->plan)
                    <a href="{{ route('admin.plans.edit', $subscription->plan) }}" class="text-xs text-brand-600 hover:underline dark:text-brand-400">
                        Edit the plan instead
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{-- =============================== FEATURES =============================== --}}
    <div class="card mb-5 p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">Features</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    "Follow plan" deletes the row, so later plan changes reach this tenant again. Forcing a value
                    freezes it until you clear it.
                </p>
            </div>
        </div>

        <div class="mt-4 space-y-5">
            @foreach ($featureGroups as $group => $features)
                <div>
                    <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        {{ $featureGroupLabels[$group] ?? ucfirst($group) }}
                    </h4>

                    <div class="space-y-2">
                        @foreach ($features as $feature)
                            @php
                                $override = $featureOverrides[$feature->id] ?? null;
                                $planSays = in_array($feature->id, $planFeatureIds, true);
                                $effective = (bool) ($effectiveFeatures[$feature->code] ?? false);
                            @endphp

                            <div @class([
                                'rounded-xl border p-3',
                                'border-brand-200 bg-brand-50/40 dark:border-brand-500/30 dark:bg-brand-500/5' => $override !== null,
                                'border-slate-200 dark:border-slate-800' => $override === null,
                            ])>
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $feature->name }}</span>
                                            @if ($effective)
                                                <span class="badge-green">On</span>
                                            @else
                                                <span class="badge-slate">Off</span>
                                            @endif
                                            @if ($override)
                                                <span class="badge-brand">Overridden</span>
                                            @endif
                                        </div>
                                        <p class="mt-0.5 text-xs text-slate-400">
                                            <code>{{ $feature->code }}</code>
                                            · plan says <span class="font-medium">{{ $planSays ? 'on' : 'off' }}</span>
                                            @if ($feature->description)
                                                — {{ $feature->description }}
                                            @endif
                                        </p>
                                        @if ($override?->reason)
                                            <p class="mt-1 text-xs text-brand-700 dark:text-brand-300">Reason: {{ $override->reason }}</p>
                                        @endif
                                    </div>

                                    {{-- Two sibling forms: one POSTs the override, one DELETEs it.
                                         Never nested — the inner form of a nested pair does not submit. --}}
                                    <div class="flex shrink-0 flex-wrap items-end gap-2">
                                        <form method="POST" action="{{ route('admin.businesses.overrides.features.store', $business) }}"
                                              class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <input type="hidden" name="feature_id" value="{{ $feature->id }}">

                                            <div>
                                                <label class="label !mb-0.5 !text-[10px]" for="fr_{{ $feature->id }}">Reason</label>
                                                <input id="fr_{{ $feature->id }}" name="reason" type="text" maxlength="500"
                                                       value="{{ $override->reason ?? '' }}"
                                                       class="input !py-1.5 w-44 text-xs" placeholder="Why this exception">
                                            </div>

                                            <div>
                                                <label class="label !mb-0.5 !text-[10px]" for="fe_{{ $feature->id }}">Force</label>
                                                <select id="fe_{{ $feature->id }}" name="is_enabled" class="input !w-auto !py-1.5 text-xs">
                                                    <option value="1" @selected($override?->is_enabled === true)>On</option>
                                                    <option value="0" @selected($override?->is_enabled === false)>Off</option>
                                                </select>
                                            </div>

                                            <button type="submit" class="btn btn-secondary !py-1.5 text-xs">Apply</button>
                                        </form>

                                        @if ($override)
                                            <form method="POST" action="{{ route('admin.businesses.overrides.features.destroy', [$business, $feature]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-ghost !py-1.5 text-xs" title="Delete the override and inherit the plan again">
                                                    <x-icon name="refresh" class="h-3.5 w-3.5" /> Follow plan
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ================================ QUOTAS ================================ --}}
    <div class="card p-5">
        <div>
            <h3 class="font-semibold text-slate-900 dark:text-white">Quotas</h3>
            <p class="mt-0.5 text-xs text-slate-400">
                Unlimited wins over the number box. <span class="font-medium">0 means nothing allowed</span> — it is a
                real limit, not "unset".
            </p>
        </div>

        <div class="mt-4 space-y-5">
            @foreach ($limitGroups as $group => $limits)
                <div>
                    <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        {{ $limitGroupLabels[$group] ?? ucfirst($group) }}
                    </h4>

                    <div class="space-y-2">
                        @foreach ($limits as $limit)
                            @php
                                $override = $limitOverrides[$limit->id] ?? null;
                                $meter = $meters[$limit->code] ?? null;

                                // Missing key = the plan configured nothing, so the
                                // registry default applies.
                                $planConfigured = array_key_exists($limit->id, $planLimitValues);
                                $planValue = $planConfigured ? $planLimitValues[$limit->id] : $limit->defaultValue();

                                $inheritLabel = $planValue === null
                                    ? 'unlimited'
                                    : number_format((float) $planValue);
                            @endphp

                            <div @class([
                                'rounded-xl border p-3',
                                'border-brand-200 bg-brand-50/40 dark:border-brand-500/30 dark:bg-brand-500/5' => $override !== null,
                                'border-slate-200 dark:border-slate-800' => $override === null,
                            ])
                                 x-data="{ unlimited: {{ $override !== null && $override->value === null ? 'true' : 'false' }} }">

                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $limit->name }}</span>
                                            @if ($override)
                                                <span class="badge-brand">Overridden</span>
                                            @endif
                                            @if ($limit->is_monthly)
                                                <span class="badge-slate">Monthly</span>
                                            @endif
                                        </div>
                                        <p class="mt-0.5 text-xs text-slate-400">
                                            <code>{{ $limit->code }}</code>
                                            · {{ $planConfigured ? 'plan' : 'registry default' }} says
                                            <span class="font-medium">{{ $inheritLabel }}</span>
                                            @if ($limit->unit)
                                                {{ $limit->unit }}
                                            @endif
                                        </p>

                                        @if ($meter)
                                            <div class="mt-2 max-w-xs">
                                                <x-meter :meter="$meter" :compact="true" />
                                            </div>
                                        @endif

                                        @if ($override?->reason)
                                            <p class="mt-1 text-xs text-brand-700 dark:text-brand-300">Reason: {{ $override->reason }}</p>
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 flex-wrap items-end gap-2">
                                        <form method="POST" action="{{ route('admin.businesses.overrides.limits.store', $business) }}"
                                              class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <input type="hidden" name="limit_id" value="{{ $limit->id }}">

                                            <div>
                                                <label class="label !mb-0.5 !text-[10px]" for="lr_{{ $limit->id }}">Reason</label>
                                                <input id="lr_{{ $limit->id }}" name="reason" type="text" maxlength="500"
                                                       value="{{ $override->reason ?? '' }}"
                                                       class="input !py-1.5 w-44 text-xs" placeholder="Why this exception">
                                            </div>

                                            <div>
                                                <label class="label !mb-0.5 !text-[10px]" for="lv_{{ $limit->id }}">Value</label>
                                                <input id="lv_{{ $limit->id }}" name="value" type="number" min="0"
                                                       value="{{ $override !== null && $override->value !== null ? $override->value : '' }}"
                                                       class="input !py-1.5 w-24 tabular-nums text-xs" placeholder="0"
                                                       x-bind:disabled="unlimited">
                                            </div>

                                            <label class="flex items-center gap-1.5 pb-2 text-xs text-slate-600 dark:text-slate-400">
                                                <input type="hidden" name="unlimited" value="0">
                                                {{-- @checked, not just x-model: without it a page with no
                                                     Alpine saves "not unlimited" over an unlimited override. --}}
                                                <input type="checkbox" name="unlimited" value="1" x-model="unlimited"
                                                       @checked($override !== null && $override->value === null)
                                                       class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                Unlimited
                                            </label>

                                            <button type="submit" class="btn btn-secondary !py-1.5 text-xs">Apply</button>
                                        </form>

                                        @if ($override)
                                            <form method="POST" action="{{ route('admin.businesses.overrides.limits.destroy', [$business, $limit]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-ghost !py-1.5 text-xs" title="Delete the override and inherit the plan again">
                                                    <x-icon name="refresh" class="h-3.5 w-3.5" /> Follow plan
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

</x-layouts.admin>
