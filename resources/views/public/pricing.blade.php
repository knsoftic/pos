<x-layouts.public title="Pricing" description="Plans built from what the system actually sells.">

    @php
        $symbol = config('subscription.currency_symbol');
        $decimals = (int) config('subscription.currency_decimals', 2);
        $defaultCycle = $cycles->first()?->value;
    @endphp

    <div x-data="{ cycle: @js($defaultCycle) }">

        {{-- ────────────────────────────── header ─────────────────────────── --}}
        <section class="border-b border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/40">
            <div class="mx-auto max-w-6xl px-4 py-16 text-center sm:px-6">
                <h1 class="text-4xl font-bold tracking-tight text-slate-900 dark:text-white">Pricing</h1>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-slate-600 dark:text-slate-300">
                    Every plan is the same software. What changes is how many shops, how many people and how deep
                    the reporting goes.
                </p>

                @if ($cycles->count() > 1)
                    <div class="mt-8 inline-flex rounded-xl bg-white p-1 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        @foreach ($cycles as $option)
                            <button type="button" @click="cycle = @js($option->value)"
                                    class="rounded-lg px-4 py-2 text-sm font-medium transition"
                                    :class="cycle === @js($option->value)
                                        ? 'bg-brand-600 text-white'
                                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white'">
                                {{ $option->label() }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- ────────────────────────────── the cards ──────────────────────── --}}
        <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            @if ($plans->isEmpty())
                {{-- Honest rather than empty: a pricing page with invented
                     numbers is the fastest way to lose somebody's trust. --}}
                <div class="mx-auto max-w-lg rounded-2xl border border-slate-200 p-10 text-center dark:border-slate-800">
                    <p class="font-medium text-slate-900 dark:text-white">Nothing is published yet</p>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        Plans are not on the website at the moment. Get in touch and we will quote you directly.
                    </p>
                    <a href="{{ route('contact') }}" class="btn btn-primary mt-5">Talk to us</a>
                </div>
            @else
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    @foreach ($plans as $plan)
                        <div class="flex flex-col rounded-2xl border p-6
                                    {{ $plan->badge
                                        ? 'border-brand-300 ring-1 ring-brand-200 dark:border-brand-700 dark:ring-brand-800'
                                        : 'border-slate-200 dark:border-slate-800' }}">
                            <div class="flex items-center justify-between gap-2">
                                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</h2>
                                @if ($plan->badge)
                                    <span class="badge-green">{{ $plan->badge }}</span>
                                @endif
                            </div>

                            @if ($plan->description)
                                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ $plan->description }}</p>
                            @endif

                            {{-- One block per cycle, shown by the toggle. All of
                                 them are real prices from `plan_prices`; a cycle
                                 the plan does not sell says so instead of
                                 quoting a number nobody can be charged. --}}
                            <div class="mt-5">
                                @foreach ($cycles as $option)
                                    @php $price = $plan->price($option); @endphp

                                    <div x-show="cycle === @js($option->value)" x-cloak>
                                        @if ($plan->isFree())
                                            <p class="text-3xl font-bold text-slate-900 dark:text-white">Free</p>
                                            <p class="mt-1 text-xs text-slate-400">No charge, no card</p>
                                        @elseif ($price)
                                            <p class="text-3xl font-bold text-slate-900 dark:text-white">
                                                {{ $symbol }}{{ number_format((float) $price->price, $decimals) }}
                                                <span class="text-sm font-medium text-slate-400">/ {{ strtolower($option->label()) }}</span>
                                            </p>
                                        @else
                                            <p class="text-lg font-medium text-slate-400">
                                                Not sold {{ strtolower($option->label()) }}
                                            </p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if ($plan->trialDays() > 0)
                                <p class="mt-3 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                    {{ $plan->trialDays() }}-day free trial
                                </p>
                            @endif

                            <div class="mt-6 flex-1">
                                <ul class="space-y-2 text-sm">
                                    @foreach ($plan->limits->take(4) as $limit)
                                        <li class="flex items-start gap-2 text-slate-600 dark:text-slate-300">
                                            <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                                            <span>
                                                {{ $limit->pivot->value === null ? 'Unlimited' : number_format((int) $limit->pivot->value) }}
                                                {{ strtolower($limit->name) }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <div class="mt-6">
                                @if ($canRegister)
                                    <a href="{{ route('register') }}" class="btn {{ $plan->badge ? 'btn-primary' : 'btn-secondary' }} w-full">
                                        Start free
                                    </a>
                                @else
                                    <a href="{{ route('contact') }}" class="btn {{ $plan->badge ? 'btn-primary' : 'btn-secondary' }} w-full">
                                        Get in touch
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- ──────────────────────── the comparison ─────────────────── --}}
                @if ($comparison !== [])
                    <div class="mt-14">
                        <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">What is in each</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            Read straight from the plans themselves — nothing on this table is typed by hand.
                        </p>

                        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-400 dark:bg-slate-900/50">
                                    <tr>
                                        <th class="px-5 py-3 font-medium">Feature</th>
                                        @foreach ($plans as $plan)
                                            <th class="px-5 py-3 text-center font-medium">{{ $plan->name }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($comparison as $row)
                                        <tr>
                                            <td class="px-5 py-3 text-slate-700 dark:text-slate-300">{{ $row['name'] }}</td>
                                            @foreach ($plans as $plan)
                                                <td class="px-5 py-3 text-center">
                                                    @if ($row['plans'][$plan->id] ?? false)
                                                        <x-icon name="check-circle" class="mx-auto h-4 w-4 text-emerald-500" />
                                                    @else
                                                        <span class="text-slate-300 dark:text-slate-700">—</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endif

            <div class="mt-14 rounded-2xl border border-slate-200 p-8 text-center dark:border-slate-800">
                <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Not sure which?</h2>
                <p class="mx-auto mt-2 max-w-lg text-sm text-slate-600 dark:text-slate-300">
                    Start on the trial and move up when the shop needs it. Changing plan keeps your data and your
                    settings — nothing is set up twice.
                </p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    @if ($canRegister && $trialPlan)
                        <a href="{{ route('register') }}" class="btn btn-primary">
                            Start on {{ $trialPlan->name }} <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    @endif
                    <a href="{{ route('contact') }}" class="btn btn-secondary">Ask us</a>
                </div>
            </div>
        </section>
    </div>

</x-layouts.public>
