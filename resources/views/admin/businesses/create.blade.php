@php
    // Cycle options per plan, so the operator can only pick a cycle the plan
    // actually sells. The backend re-checks this (#190) — this is convenience,
    // not the enforcement.
    $planCycles = $plans->mapWithKeys(fn ($plan) => [
        $plan->id => $plan->activePrices()->map(fn ($price) => [
            'value' => $price->billing_cycle->value,
            'label' => $price->billing_cycle->label().' — '.$price->formatted().' '.$price->periodLabel(),
        ])->values()->all(),
    ])->all();

    $planTrials = $plans->mapWithKeys(fn ($plan) => [$plan->id => $plan->trialDays()])->all();
    $firstPlanId = old('plan_id', $plans->first()?->id);
@endphp

<x-layouts.admin title="New business">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('admin.businesses.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to businesses
        </a>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            The tenant, its owner login and its first subscription are created together in one transaction — if any
            part fails, nothing is written.
        </p>
    </div>

    @if ($plans->isEmpty())
        <div class="card p-10 text-center">
            <x-icon name="alert" class="mx-auto h-10 w-10 text-amber-500" />
            <p class="mt-3 font-medium text-slate-700 dark:text-slate-300">No active plans</p>
            <p class="mt-1 text-sm text-slate-400">A business cannot be created without a plan to subscribe it to.</p>
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary mt-4 inline-flex">
                <x-icon name="plus" class="h-4 w-4" /> Create a plan first
            </a>
        </div>
    @else
        <form method="POST" action="{{ route('admin.businesses.store') }}" class="space-y-6">
            @csrf

            @include('admin.businesses.fields')

            {{-- ------------------------------------------------- owner login --}}
            <div class="card p-5">
                <div class="flex items-start gap-3">
                    <x-icon name="key" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600 dark:text-brand-400" />
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white">Owner login</h3>
                        <p class="mt-0.5 text-xs text-slate-400">
                            The first user of the tenant, created as the business owner. Emails are unique across the
                            whole system, because one login cannot belong to two tenants.
                        </p>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="owner_name">Owner name</label>
                        <input id="owner_name" name="owner_name" type="text" required maxlength="255"
                               value="{{ old('owner_name') }}" class="input" autocomplete="off">
                        @error('owner_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label" for="owner_email">Owner email</label>
                        <input id="owner_email" name="owner_email" type="email" required maxlength="255"
                               value="{{ old('owner_email') }}" class="input" autocomplete="off">
                        @error('owner_email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label" for="owner_phone">Owner phone</label>
                        <input id="owner_phone" name="owner_phone" type="text" maxlength="40"
                               value="{{ old('owner_phone') }}" class="input">
                        @error('owner_phone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="hidden sm:block"></div>

                    <div x-data="{ show: false }">
                        <label class="label" for="owner_password">Password</label>
                        <div class="relative">
                            <input id="owner_password" name="owner_password" required class="input !pr-10"
                                   x-bind:type="show ? 'text' : 'password'" autocomplete="new-password">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                    x-on:click="show = ! show" tabindex="-1">
                                <x-icon x-show="! show" name="eye" class="h-4 w-4" />
                                <x-icon x-show="show" name="eye-off" class="h-4 w-4" style="display:none" />
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Minimum 8 characters, mixed case, a number and a symbol.</p>
                        @error('owner_password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label" for="owner_password_confirmation">Confirm password</label>
                        <input id="owner_password_confirmation" name="owner_password_confirmation" type="password"
                               required class="input" autocomplete="new-password">
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------ subscription --}}
            <div class="card p-5"
                 x-data="{
                     planId: '{{ $firstPlanId }}',
                     cycle: '',
                     desired: '{{ old('billing_cycle') }}',
                     trial: {{ old('start_trial') ? 'true' : 'false' }},
                     cycles: @js($planCycles),
                     trials: @js($planTrials),
                     first(id) { return (this.cycles[id] ?? [])[0]?.value ?? '' },
                     options() { return this.cycles[this.planId] ?? [] },
                 }"
                 x-init="$nextTick(() => { cycle = desired || first(planId) }); $watch('planId', v => { cycle = first(v) })">

                <div class="flex items-start gap-3">
                    <x-icon name="credit-card" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600 dark:text-brand-400" />
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white">First subscription</h3>
                        <p class="mt-0.5 text-xs text-slate-400">
                            A tenant with no subscription cannot use the app, so one is always created here.
                        </p>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="plan_id">Plan</label>
                        <select id="plan_id" name="plan_id" required class="input" x-model="planId">
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->id }}">
                                    {{ $plan->name }}@unless ($plan->is_public) (private)@endunless
                                </option>
                            @endforeach
                        </select>
                        @error('plan_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="label" for="billing_cycle">Billing cycle</label>
                        <select id="billing_cycle" name="billing_cycle" required class="input" x-model="cycle"
                                x-bind:disabled="trial">
                            <template x-for="option in options()" x-bind:key="option.value">
                                <option x-bind:value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400" x-show="options().length === 0" style="display:none">
                            This plan has no active price — only a trial can be started on it.
                        </p>
                        <p class="mt-1 text-xs text-slate-400" x-show="trial" style="display:none">
                            Trials always run on a monthly footing; the cycle is chosen at the first renewal.
                        </p>
                        @error('billing_cycle') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                        <label class="flex items-start gap-2.5">
                            <input type="hidden" name="start_trial" value="0">
                            <input type="checkbox" name="start_trial" value="1" x-model="trial"
                                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-sm">
                                <span class="font-medium text-slate-800 dark:text-slate-200">Start as a free trial</span>
                                <span class="block text-xs text-slate-400">
                                    No charge, full plan entitlements, and access stops automatically when the trial ends.
                                </span>
                            </span>
                        </label>

                        <div class="mt-3 pl-7" x-show="trial" style="display:none">
                            <label class="label" for="trial_days">Trial length (days)</label>
                            <input id="trial_days" name="trial_days" type="number" min="1" max="365"
                                   value="{{ old('trial_days') }}" class="input w-40 tabular-nums"
                                   x-bind:placeholder="trials[planId] ?? {{ $defaultTrialDays }}">
                            <p class="mt-1 text-xs text-slate-400">
                                Blank uses the plan's own trial length (<span x-text="trials[planId] ?? {{ $defaultTrialDays }}"></span> days).
                            </p>
                            @error('trial_days') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('admin.businesses.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <x-icon name="check" class="h-4 w-4" /> Create business
                </button>
            </div>
        </form>
    @endif

</x-layouts.admin>
