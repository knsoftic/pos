@php
    use App\Enums\BillingCycle;

    /*
     | Only offer a cycle toggle for cycles somebody actually sells, otherwise the
     | page invites a click that shows "not available" on every card.
     */
    $sellableCycles = collect($cycles)->filter(
        fn (BillingCycle $cycle) => $plans->contains(fn ($plan) => $plan->price($cycle) !== null)
    )->values();

    // Lead with monthly when it exists — it is the number people compare on.
    $defaultCycle = $sellableCycles->firstWhere(fn (BillingCycle $c) => $c === BillingCycle::Monthly)
        ?? $sellableCycles->first();

    $currentPlan = $plans->firstWhere('id', $currentPlanId);
@endphp

<x-layouts.app title="Plans">

    <x-flash />

    @if (session('whatsapp'))
        {{-- ⚠️ The request is ALREADY FILED by the time this shows. This button
             only opens WhatsApp on this device with the message prefilled --
             nothing is sent from the server, and the wording says so, because
             promising a delivery nobody can guarantee is how a shop ends up
             waiting on a message that was never sent. --}}
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-emerald-900 dark:text-emerald-200">Request saved.</p>
                <p class="mt-0.5 text-sm text-emerald-800 dark:text-emerald-300/80">
                    It is already with us. To reach a person faster, send it on WhatsApp too — the
                    message opens ready to send.
                </p>
            </div>
            <a href="{{ session('whatsapp') }}" target="_blank" rel="noopener"
               class="btn btn-primary shrink-0">
                <x-icon name="mail" class="h-4 w-4" /> Send on WhatsApp
            </a>
        </div>
    @endif

    <div x-data="{ cycle: '{{ $defaultCycle?->value }}' }">

        {{-- ================================ HEADER ================================ --}}
        <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.billing.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
                    <x-icon name="arrow-left" class="h-4 w-4" /> Back to billing
                </a>
                <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                    @if ($currentPlan)
                        You are on <strong class="text-slate-700 dark:text-slate-300">{{ $currentPlan->name }}</strong>.
                        Everything below is what each plan allows — your own usage is on the
                        <a href="{{ route('app.billing.index') }}" class="text-brand-600 hover:underline dark:text-brand-400">billing page</a>.
                    @else
                        Choose the plan that fits, and we will set it up for you.
                    @endif
                </p>
            </div>

            {{-- Cycle toggle --}}
            @if ($sellableCycles->count() > 1)
                <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-900">
                    @foreach ($sellableCycles as $cycle)
                        <button type="button" x-on:click="cycle = '{{ $cycle->value }}'"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                                x-bind:class="cycle === '{{ $cycle->value }}'
                                    ? 'bg-brand-600 text-white shadow-sm'
                                    : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'">
                            {{ $cycle->label() }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ================================= CARDS ================================= --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($plans as $plan)
                @php
                    $isCurrent = $plan->id === $currentPlanId;
                    $monthly = $plan->price(BillingCycle::Monthly);
                @endphp

                <div @class([
                    'card relative flex flex-col p-5',
                    'ring-2 ring-brand-500' => $isCurrent,
                ])>
                    @if ($isCurrent)
                        <span class="absolute -top-2.5 left-5 rounded-full bg-brand-600 px-2 py-0.5 text-[11px] font-semibold text-white">
                            Your plan
                        </span>
                    @elseif ($plan->badge)
                        <span class="absolute -top-2.5 left-5 rounded-full bg-slate-900 px-2 py-0.5 text-[11px] font-semibold text-white dark:bg-white dark:text-slate-900">
                            {{ $plan->badge }}
                        </span>
                    @endif

                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-bold text-slate-900 dark:text-white">{{ $plan->name }}</h3>
                        @unless ($plan->is_public)
                            <span class="badge-slate" title="A negotiated plan, not on the public list">Private</span>
                        @endunless
                    </div>

                    @if ($plan->description)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $plan->description }}</p>
                    @endif

                    {{-- Every cycle is rendered and revealed by the toggle, so the
                         numbers are server-computed and cannot drift from the DB. --}}
                    <div class="mt-4 min-h-[4.5rem]">
                        @foreach ($sellableCycles as $cycle)
                            @php
                                $price = $plan->price($cycle);
                                $perMonth = $price?->perMonth();
                                $saving = ($monthly !== null && $perMonth !== null && (float) $monthly->price > 0)
                                    ? (int) round((1 - ($perMonth / (float) $monthly->price)) * 100)
                                    : 0;
                            @endphp

                            <div x-show="cycle === '{{ $cycle->value }}'" style="display:none">
                                @if ($price === null)
                                    <p class="text-sm text-slate-400">Not sold {{ $cycle->suffix() }}</p>
                                @else
                                    <p class="flex items-baseline gap-1">
                                        <span class="text-3xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $price->formatted() }}</span>
                                        <span class="text-sm text-slate-400">{{ $price->periodLabel() }}</span>
                                    </p>

                                    @if ($price->isFree())
                                        <p class="mt-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">Free forever</p>
                                    @elseif ($saving > 0)
                                        <p class="mt-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                            Save {{ $saving }}% vs monthly
                                        </p>
                                    @elseif ($cycle === BillingCycle::Lifetime)
                                        <p class="mt-1 text-xs font-medium text-brand-600 dark:text-brand-400">One payment, never expires</p>
                                    @else
                                        <p class="mt-1 text-xs text-slate-400">Billed {{ strtolower($cycle->label()) }}</p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-slate-400">
                        {{ $plan->trialDays() }}-day trial · {{ $plan->graceDays() }}-day grace period
                    </p>

                    <div class="mt-4 flex-1"></div>

                    @if ($isCurrent)
                        <span class="btn btn-secondary w-full cursor-default justify-center opacity-70">
                            <x-icon name="check" class="h-4 w-4" /> Current plan
                        </span>
                    @else
                        {{-- No self-serve checkout in this release (#82), so this
                             files a REQUEST -- it does not move the shop or take
                             money. It used to be a mailto:, which needed a mail
                             client on the shopkeeper's device and left no record
                             anywhere when there wasn't one. --}}
                        @if ($openRequest?->plan_id === $plan->id)
                            <span class="btn btn-secondary w-full cursor-default justify-center">
                                <x-icon name="check" class="h-4 w-4" /> Requested
                            </span>
                            <p class="mt-1.5 text-center text-xs text-slate-400">
                                Asked {{ $openRequest->created_at?->diffForHumans() }} — we will be in touch.
                            </p>
                        @else
                            <form method="POST" action="{{ route('app.billing.plans.request', $plan) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary w-full justify-center">
                                    <x-icon name="arrow-right" class="h-4 w-4" /> Request {{ $plan->name }}
                                </button>
                            </form>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- ============================== COMPARISON ============================== --}}
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5 dark:border-slate-800">
            <h3 class="font-semibold text-slate-900 dark:text-white">Full comparison</h3>
            <p class="mt-0.5 text-xs text-slate-400">
                Quotas shown as a plan's own ceiling. Your account may have an agreed exception — the
                <a href="{{ route('app.billing.index') }}" class="text-brand-600 hover:underline dark:text-brand-400">usage meters</a>
                always show what actually applies to you.
            </p>
        </div>

        <div class="table-wrap">
            <table class="w-full min-w-[700px] text-sm">
                <thead>
                    <tr>
                        <th class="th sticky left-0 z-10 bg-slate-50 text-left dark:bg-slate-800/80">Feature</th>
                        @foreach ($plans as $plan)
                            <th @class([
                                'th text-center',
                                'text-brand-600 dark:text-brand-400' => $plan->id === $currentPlanId,
                            ])>
                                {{ $plan->name }}
                                @if ($plan->id === $currentPlanId)
                                    <span class="block text-[10px] font-normal normal-case">your plan</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    {{-- quotas first: they are what people actually outgrow --}}
                    @foreach ($limits as $group => $groupLimits)
                        <tr>
                            <td class="bg-slate-50 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"
                                colspan="{{ $plans->count() + 1 }}">
                                {{ $limitGroupLabels[$group] ?? ucfirst($group) }}
                            </td>
                        </tr>
                        @foreach ($groupLimits as $limit)
                            <tr>
                                <td class="td sticky left-0 z-10 bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-300">
                                    {{ $limit->name }}
                                    @if ($limit->is_monthly)
                                        <span class="text-xs text-slate-400">/ month</span>
                                    @endif
                                </td>
                                @foreach ($plans as $plan)
                                    @php
                                        $quotas = $limitMap[$plan->id] ?? [];
                                        // A missing key means the plan configured nothing,
                                        // so the registry default applies.
                                        $configured = array_key_exists($limit->id, $quotas);
                                        $value = $configured ? $quotas[$limit->id] : $limit->defaultValue();
                                    @endphp
                                    <td class="td text-center tabular-nums">
                                        @if ($value === null)
                                            <span class="font-medium text-emerald-600 dark:text-emerald-400">Unlimited</span>
                                        @elseif ((int) $value === 0)
                                            <span class="text-slate-300 dark:text-slate-600">—</span>
                                        @else
                                            <span @class([
                                                'text-slate-700 dark:text-slate-300' => $configured,
                                                'text-slate-400' => ! $configured,
                                            ])>{{ number_format((float) $value) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach

                    {{-- then features --}}
                    @foreach ($features as $group => $groupFeatures)
                        <tr>
                            <td class="bg-slate-50 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"
                                colspan="{{ $plans->count() + 1 }}">
                                {{ $featureGroupLabels[$group] ?? ucfirst($group) }}
                            </td>
                        </tr>
                        @foreach ($groupFeatures as $feature)
                            <tr>
                                <td class="td sticky left-0 z-10 bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-300"
                                    @if ($feature->description) title="{{ $feature->description }}" @endif>
                                    {{ $feature->name }}
                                </td>
                                @foreach ($plans as $plan)
                                    @php
                                        $planFeatures = $featureMap[$plan->id] ?? [];
                                        $on = array_key_exists($feature->id, $planFeatures)
                                            ? $planFeatures[$feature->id]
                                            : (bool) $feature->default_enabled;
                                    @endphp
                                    <td class="td text-center">
                                        @if ($on)
                                            <x-icon name="check" class="mx-auto h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                        @else
                                            <x-icon name="minus" class="mx-auto h-4 w-4 text-slate-300 dark:text-slate-600" />
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</x-layouts.app>
