{{--
    Single-page plan editor — details, per-cycle prices, feature matrix and quota
    matrix all POST together and are saved in one transaction by PlanController.

    Expects: $plan, $cycles, $existingPrices, $featureGroups, $limitGroups,
             $featureGroupLabels, $limitGroupLabels, $selectedFeatureIds,
             $planLimits, $configuredLimitIds, $action, $method
--}}
@php
    $oldFeatures = old('features', $selectedFeatureIds);
    $currency = config('subscription.currency_symbol');
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    {{-- ============================ DETAILS ============================ --}}
    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Plan details</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Name</label>
                <input id="name" name="name" type="text" required maxlength="255"
                       value="{{ old('name', $plan->name) }}" class="input" placeholder="Professional">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="slug">Slug</label>
                <input id="slug" name="slug" type="text" maxlength="255"
                       value="{{ old('slug', $plan->slug) }}" class="input" placeholder="professional">
                <p class="mt-1 text-xs text-slate-400">Leave blank to derive it from the name. Used in URLs and by the seeders.</p>
                @error('slug') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="label" for="description">Description</label>
                <textarea id="description" name="description" rows="2" maxlength="1000" class="input"
                          placeholder="What this plan is for — shown on the pricing page.">{{ old('description', $plan->description) }}</textarea>
                @error('description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="badge">Badge</label>
                <input id="badge" name="badge" type="text" maxlength="40"
                       value="{{ old('badge', $plan->badge) }}" class="input" placeholder="Most popular">
                @error('badge') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="sort_order">Display order</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="9999"
                       value="{{ old('sort_order', $plan->sort_order ?? 0) }}" class="input">
                @error('sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="trial_days">Trial days</label>
                <input id="trial_days" name="trial_days" type="number" min="0" max="365"
                       value="{{ old('trial_days', $plan->trial_days) }}" class="input"
                       placeholder="Default: {{ (int) config('subscription.trial_days') }}">
                <p class="mt-1 text-xs text-slate-400">Blank uses the system default.</p>
                @error('trial_days') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="grace_days">Grace days</label>
                <input id="grace_days" name="grace_days" type="number" min="0" max="90"
                       value="{{ old('grace_days', $plan->grace_days) }}" class="input"
                       placeholder="Default: {{ (int) config('subscription.grace_days') }}">
                <p class="mt-1 text-xs text-slate-400">Days of continued access after expiry.</p>
                @error('grace_days') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-6 border-t border-slate-100 pt-4 dark:border-slate-800">
            <label class="flex items-start gap-2.5">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                       @checked(old('is_active', $plan->is_active))>
                <span class="text-sm">
                    <span class="font-medium text-slate-800 dark:text-slate-200">Active</span>
                    <span class="block text-xs text-slate-400">Can be assigned to businesses.</span>
                </span>
            </label>

            <label class="flex items-start gap-2.5">
                <input type="hidden" name="is_public" value="0">
                <input type="checkbox" name="is_public" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                       @checked(old('is_public', $plan->is_public))>
                <span class="text-sm">
                    <span class="font-medium text-slate-800 dark:text-slate-200">Show on website</span>
                    <span class="block text-xs text-slate-400">Private plans can still be assigned by an operator.</span>
                </span>
            </label>
        </div>
    </div>

    {{-- ============================= PRICES ============================= --}}
    <div class="card p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">Prices</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    One price per billing cycle. Enable only the cycles you sell — a cycle with no price cannot be subscribed to.
                </p>
            </div>
            <span class="badge-slate">{{ config('subscription.currency') }}</span>
        </div>

        @error('prices') <p class="mt-3 text-xs text-rose-600">{{ $message }}</p> @enderror

        <div class="mt-4 space-y-2">
            @foreach ($cycles as $cycle)
                @php
                    $key = $cycle->value;
                    $existing = $existingPrices[$key] ?? null;
                    $enabled = (bool) old("prices.{$key}.enabled", $existing !== null);
                    $price = old("prices.{$key}.price", $existing?->price);
                    $customDays = old("prices.{$key}.custom_days", $existing?->custom_days);
                @endphp

                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800"
                     x-data="{ on: {{ $enabled ? 'true' : 'false' }} }"
                     :class="on ? 'bg-white dark:bg-slate-900' : 'bg-slate-50 dark:bg-slate-900/40'">
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="flex w-40 shrink-0 items-center gap-2.5">
                            <input type="hidden" name="prices[{{ $key }}][enabled]" value="0">
                            <input type="checkbox" name="prices[{{ $key }}][enabled]" value="1" x-model="on"
                                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $cycle->label() }}</span>
                        </label>

                        <div class="relative w-36">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">{{ $currency }}</span>
                            <input type="number" step="0.01" min="0" name="prices[{{ $key }}][price]"
                                   value="{{ $price }}" class="input !pl-7 tabular-nums" placeholder="0.00"
                                   :disabled="! on">
                        </div>

                        <span class="text-xs text-slate-400">{{ $cycle->suffix() }}</span>

                        @if ($cycle->requiresCustomDays())
                            <div class="flex items-center gap-2">
                                <input type="number" min="1" max="36500" name="prices[{{ $key }}][custom_days]"
                                       value="{{ $customDays }}" class="input w-28 tabular-nums" placeholder="Days"
                                       :disabled="! on">
                                <span class="text-xs text-slate-400">days per period</span>
                            </div>
                        @endif

                        @if ($cycle === \App\Enums\BillingCycle::Lifetime)
                            <span class="badge-amber">Never expires</span>
                        @endif
                    </div>

                    @error("prices.{$key}.price") <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                    @error("prices.{$key}.custom_days") <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>
    </div>

    {{-- ============================ FEATURES ============================ --}}
    <div class="card p-5" x-data>
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">Features</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Ticked features are granted to every subscriber. Unticked is stored explicitly as "off", so the
                    comparison matrix can tell "not included" from "not configured".
                </p>
            </div>
            <span class="badge-slate">{{ count($oldFeatures) }} selected</span>
        </div>

        <div class="mt-4 space-y-5">
            @foreach ($featureGroups as $group => $features)
                <div>
                    <div class="mb-2 flex items-center justify-between gap-2 border-b border-slate-100 pb-1.5 dark:border-slate-800">
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            {{ $featureGroupLabels[$group] ?? ucfirst($group) }}
                        </h4>
                        <div class="flex gap-1 text-xs">
                            <button type="button" class="text-brand-600 hover:underline dark:text-brand-400"
                                    @click="$root.querySelectorAll('[data-group=&quot;{{ $group }}&quot;]').forEach(c => c.checked = true)">All</button>
                            <span class="text-slate-300">·</span>
                            <button type="button" class="text-slate-500 hover:underline"
                                    @click="$root.querySelectorAll('[data-group=&quot;{{ $group }}&quot;]').forEach(c => c.checked = false)">None</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($features as $feature)
                            <label class="flex items-start gap-2.5">
                                <input type="checkbox" name="features[]" value="{{ $feature->id }}"
                                       data-group="{{ $group }}"
                                       class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                       @checked(in_array($feature->id, $oldFeatures))>
                                <span class="min-w-0 text-sm">
                                    <span class="block truncate text-slate-800 dark:text-slate-200" title="{{ $feature->code }}">{{ $feature->name }}</span>
                                    @if ($feature->description)
                                        <span class="block truncate text-xs text-slate-400">{{ $feature->description }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ============================= LIMITS ============================= --}}
    <div class="card p-5">
        <div>
            <h3 class="font-semibold text-slate-900 dark:text-white">Quotas</h3>
            <p class="mt-0.5 text-xs text-slate-400">
                <strong class="font-semibold text-slate-500 dark:text-slate-400">Inherit</strong> leaves the quota
                unconfigured and falls back to the system default ·
                <strong class="font-semibold text-slate-500 dark:text-slate-400">Unlimited</strong> stores no ceiling ·
                <strong class="font-semibold text-slate-500 dark:text-slate-400">Custom</strong> stores the number
                (0 means the feature is effectively off).
            </p>
        </div>

        <div class="mt-4 space-y-5">
            @foreach ($limitGroups as $group => $limits)
                <div>
                    <h4 class="mb-2 border-b border-slate-100 pb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        {{ $limitGroupLabels[$group] ?? ucfirst($group) }}
                    </h4>

                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        @foreach ($limits as $limit)
                            @php
                                $isConfigured = in_array($limit->id, $configuredLimitIds);
                                $currentValue = $planLimits[$limit->id] ?? null;
                                $derivedMode = match (true) {
                                    ! $isConfigured => 'inherit',
                                    $currentValue === null => 'unlimited',
                                    default => 'custom',
                                };
                                $mode = old("limits.{$limit->id}.mode", $derivedMode);
                            @endphp

                            <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800"
                                 x-data="{ mode: '{{ $mode }}' }">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="min-w-0 flex-1 text-sm font-medium text-slate-800 dark:text-slate-200"
                                          title="{{ $limit->code }}">
                                        {{ $limit->name }}
                                        @if ($limit->is_monthly)
                                            <span class="text-xs font-normal text-slate-400">/ month</span>
                                        @endif
                                    </span>

                                    <select name="limits[{{ $limit->id }}][mode]" x-model="mode" class="input !w-auto !py-1.5 text-xs">
                                        <option value="inherit">Inherit</option>
                                        <option value="unlimited">Unlimited</option>
                                        <option value="custom">Custom</option>
                                    </select>

                                    <input type="number" min="0" name="limits[{{ $limit->id }}][value]"
                                           value="{{ old("limits.{$limit->id}.value", $currentValue) }}"
                                           class="input w-28 tabular-nums" placeholder="0"
                                           x-show="mode === 'custom'" style="display:none">
                                </div>

                                <p class="mt-1 text-xs text-slate-400" x-show="mode === 'inherit'" style="display:none">
                                    Falls back to {{ $limit->default_unlimited ? 'unlimited' : number_format((float) ($limit->default_value ?? 0)) }}
                                    {{ $limit->unit }}
                                </p>
                                @error("limits.{$limit->id}.value") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="sticky bottom-0 -mx-4 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/90 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 sm:-mx-6 sm:px-6">
        <a href="{{ route('admin.plans.index') }}" class="btn btn-ghost">Cancel</a>
        <div class="flex items-center gap-2">
            @if ($plan->exists)
                <span class="hidden text-xs text-slate-400 sm:block">Saving refreshes every subscriber's entitlements.</span>
            @endif
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-4 w-4" />
                {{ $plan->exists ? 'Save plan' : 'Create plan' }}
            </button>
        </div>
    </div>
</form>
