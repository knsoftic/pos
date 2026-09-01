<x-layouts.public title="Questions" description="What people ask before signing up.">

    <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <h1 class="text-4xl font-bold tracking-tight text-slate-900 dark:text-white">Questions</h1>
        <p class="mt-4 text-lg text-slate-600 dark:text-slate-300">
            The ones people actually ask. If yours is not here,
            <a href="{{ route('contact') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">ask us</a>.
        </p>

        <dl class="mt-10 divide-y divide-slate-200 dark:divide-slate-800">
            @foreach ($faqs as $faq)
                <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" class="py-5">
                    <dt>
                        <button @click="open = ! open" class="flex w-full items-start justify-between gap-4 text-left">
                            <span class="font-medium text-slate-900 dark:text-white">{{ $faq['q'] }}</span>
                            <x-icon name="chevron-down" class="mt-1 h-4 w-4 shrink-0 text-slate-400 transition" ::class="open && 'rotate-180'" />
                        </button>
                    </dt>
                    <dd x-show="open" x-cloak x-transition.duration.200ms
                        class="mt-3 leading-relaxed text-slate-600 dark:text-slate-300">
                        {{ $faq['a'] }}
                    </dd>
                </div>
            @endforeach
        </dl>

        @if ($canRegister)
            <div class="mt-12 rounded-2xl border border-slate-200 p-6 text-center dark:border-slate-800">
                <p class="font-medium text-slate-900 dark:text-white">Still deciding?</p>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">The trial is the whole system, not a demo.</p>
                <a href="{{ route('register') }}" class="btn btn-primary mt-4">Start free</a>
            </div>
        @endif
    </div>

</x-layouts.public>
