<x-layouts.public :title="$page['title']" :description="$page['lead']">

    <section class="border-b border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/40">
        <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-400">{{ $page['eyebrow'] }}</p>
            <h1 class="mt-2 text-4xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $page['title'] }}</h1>
            <p class="mt-4 max-w-2xl text-lg leading-relaxed text-slate-600 dark:text-slate-300">{{ $page['lead'] }}</p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6">
        <div class="space-y-14">
            @foreach ($page['sections'] as $section)
                <section>
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $section['title'] }}</h2>
                    <p class="mt-3 leading-relaxed text-slate-600 dark:text-slate-300">{{ $section['body'] }}</p>

                    <ul class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($section['points'] as $point)
                            <li class="flex items-start gap-2.5 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                                <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                                <span class="text-sm leading-relaxed text-slate-700 dark:text-slate-300">{{ $point }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <div class="mt-16 rounded-2xl border border-slate-200 p-8 text-center dark:border-slate-800">
            <h2 class="text-xl font-semibold text-slate-900 dark:text-white">See it with your own products</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-slate-600 dark:text-slate-300">
                @if ($canRegister)
                    {{ $trialDays }} days free, no card. Whatever you set up is still there if you carry on.
                @else
                    Sign-ups are closed at the moment — tell us what you need and we will set you up.
                @endif
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                @if ($canRegister)
                    <a href="{{ route('register') }}" class="btn btn-primary">Start free <x-icon name="arrow-right" class="h-4 w-4" /></a>
                @endif
                <a href="{{ route('pricing') }}" class="btn btn-secondary">See pricing</a>
            </div>
        </div>
    </div>

</x-layouts.public>
