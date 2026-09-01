<x-layouts.public>

    {{-- ──────────────────────────────── hero ──────────────────────────────── --}}
    <section class="relative overflow-hidden">
        <div aria-hidden="true"
             class="pointer-events-none absolute inset-x-0 -top-40 h-[500px] bg-gradient-to-b from-brand-50 via-white to-white dark:from-brand-500/10 dark:via-slate-950 dark:to-slate-950"></div>

        <div class="relative mx-auto max-w-6xl px-4 pb-16 pt-16 sm:px-6 sm:pb-20 sm:pt-24">
            <div class="max-w-2xl">
                @if ($canRegister && $trialDays)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/30">
                        <x-icon name="zap" class="h-3.5 w-3.5" />
                        {{ $trialDays }}-day free trial · no card
                    </span>
                @endif

                <h1 class="mt-5 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                    Run the shop, not the software
                </h1>

                <p class="mt-5 text-lg leading-relaxed text-slate-600 dark:text-slate-300">
                    {{ config('brand.product') }} is a point of sale, stock system and set of books for shops that
                    have more than one till, more than one branch, or more than one person they have to trust.
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    @if ($canRegister)
                        <a href="{{ route('register') }}" class="btn btn-primary">
                            Start free <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    @else
                        <a href="{{ route('contact') }}" class="btn btn-primary">
                            Get in touch <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    @endif

                    <a href="{{ route('pricing') }}" class="btn btn-secondary">See pricing</a>
                </div>

                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                    Already have an account?
                    <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Sign in</a>
                </p>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────── pillars ────────────────────────────── --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            @foreach ($pillars as $pillar)
                <div class="rounded-2xl border border-slate-200 p-6 dark:border-slate-800">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                        <x-icon :name="$pillar['icon']" class="h-5 w-5" />
                    </span>
                    <h2 class="mt-4 font-semibold text-slate-900 dark:text-white">{{ $pillar['title'] }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ $pillar['body'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ──────────────────────────── the modules ───────────────────────────── --}}
    <section class="border-y border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/40">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-400">What is in it</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                    One system, from the shelf to the statement
                </h2>
            </div>

            <div class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($pages as $slug => $page)
                    <a href="{{ route('page', $slug) }}"
                       class="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-brand-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-700">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">
                                    {{ $page['title'] }}
                                </h3>
                                <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ $page['lead'] }}</p>
                            </div>
                            <x-icon name="arrow-right" class="h-5 w-5 shrink-0 text-slate-300 group-hover:text-brand-600 dark:text-slate-600" />
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ───────────────────────────── the plans ────────────────────────────── --}}
    @if ($plans->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-400">Pricing</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Pick the size of shop you are
                </h2>
                <p class="mt-3 text-slate-600 dark:text-slate-300">
                    Every plan includes the till, stock and reports. The bigger ones add branches, deeper reporting
                    and the exports an accountant asks for.
                </p>
            </div>

            @php
                // ⚠️ Tailwind scans templates at BUILD time, so an interpolated
                // class name like `md:grid-cols-{{ $n }}` is never generated.
                // The literal strings have to appear in the file.
                $columns = match (min(3, $plans->count())) {
                    1 => 'md:grid-cols-1',
                    2 => 'md:grid-cols-2',
                    default => 'md:grid-cols-3',
                };
            @endphp

            <div class="mt-10 grid grid-cols-1 gap-5 {{ $columns }}">
                @foreach ($plans->take(3) as $plan)
                    @php $entry = $plan->entryPrice(); @endphp

                    <div class="rounded-2xl border p-6 {{ $plan->badge ? 'border-brand-300 ring-1 ring-brand-200 dark:border-brand-700 dark:ring-brand-800' : 'border-slate-200 dark:border-slate-800' }}">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</h3>
                            @if ($plan->badge)
                                <span class="badge-green">{{ $plan->badge }}</span>
                            @endif
                        </div>

                        <p class="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                            @if ($plan->isFree())
                                Free
                            @elseif ($entry)
                                {{ config('subscription.currency_symbol') }}{{ number_format((float) $entry->price, (int) config('subscription.currency_decimals', 2)) }}
                                <span class="text-sm font-medium text-slate-400">/ {{ strtolower($entry->billing_cycle->label()) }}</span>
                            @else
                                <span class="text-lg font-medium text-slate-400">On request</span>
                            @endif
                        </p>

                        @if ($plan->description)
                            <p class="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ $plan->description }}</p>
                        @endif

                        <a href="{{ route('pricing') }}" class="btn btn-secondary mt-6 w-full">See what is included</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ─────────────────────────────── the FAQ ────────────────────────────── --}}
    <section class="border-t border-slate-200 dark:border-slate-800">
        <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
            <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Before you ask</h2>

            <dl class="mt-8 divide-y divide-slate-200 dark:divide-slate-800">
                @foreach ($faqs as $faq)
                    <div x-data="{ open: false }" class="py-4">
                        <dt>
                            <button @click="open = ! open" class="flex w-full items-start justify-between gap-4 text-left">
                                <span class="font-medium text-slate-900 dark:text-white">{{ $faq['q'] }}</span>
                                <x-icon name="chevron-down" class="mt-1 h-4 w-4 shrink-0 text-slate-400 transition" ::class="open && 'rotate-180'" />
                            </button>
                        </dt>
                        <dd x-show="open" x-cloak x-transition.duration.200ms
                            class="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            {{ $faq['a'] }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            <a href="{{ route('faq') }}" class="mt-6 inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                All questions <x-icon name="arrow-right" class="h-4 w-4" />
            </a>
        </div>
    </section>

    {{-- ──────────────────────────────── CTA ──────────────────────────────── --}}
    <section class="border-t border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/40">
        <div class="mx-auto max-w-4xl px-4 py-16 text-center sm:px-6">
            <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                @if ($canRegister)
                    Set your shop up this afternoon
                @else
                    Let us set your shop up
                @endif
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-slate-600 dark:text-slate-300">
                @if ($canRegister)
                    A real workspace with your own products and staff — not a demo. Whatever you set up during the
                    trial is still there afterwards.
                @else
                    Sign-ups are closed at the moment, but we are still taking shops on. Tell us what you need.
                @endif
            </p>

            <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                @if ($canRegister)
                    <a href="{{ route('register') }}" class="btn btn-primary">
                        Start free <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                @endif
                <a href="{{ route('contact') }}" class="btn btn-secondary">Talk to us</a>
            </div>
        </div>
    </section>

</x-layouts.public>
