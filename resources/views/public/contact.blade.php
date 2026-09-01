<x-layouts.public title="Contact" description="How to reach us.">

    <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <h1 class="text-4xl font-bold tracking-tight text-slate-900 dark:text-white">Get in touch</h1>
        <p class="mt-4 text-lg text-slate-600 dark:text-slate-300">
            A person reads these. Tell us what your shop does and what is not working, and we will tell you
            honestly whether this helps.
        </p>

        {{-- ⚠️ Contact DETAILS, not a form, and deliberately so. A contact form
             needs mail delivery, spam handling and somewhere for the messages to
             land — none of which is wired up yet, and a form that silently
             swallows enquiries is worse than no form at all. It becomes a form
             when mail does. --}}
        <div class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if (config('brand.support_email'))
                <a href="mailto:{{ config('brand.support_email') }}"
                   class="group rounded-2xl border border-slate-200 p-6 transition hover:border-brand-300 dark:border-slate-800 dark:hover:border-brand-700">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                        <x-icon name="mail" class="h-5 w-5" />
                    </span>
                    <p class="mt-4 font-semibold text-slate-900 dark:text-white">Support</p>
                    <p class="mt-1 text-sm text-slate-600 group-hover:text-brand-700 dark:text-slate-300 dark:group-hover:text-brand-300">
                        {{ config('brand.support_email') }}
                    </p>
                </a>
            @endif

            @if (config('brand.sales_email'))
                <a href="mailto:{{ config('brand.sales_email') }}"
                   class="group rounded-2xl border border-slate-200 p-6 transition hover:border-brand-300 dark:border-slate-800 dark:hover:border-brand-700">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">
                        <x-icon name="users" class="h-5 w-5" />
                    </span>
                    <p class="mt-4 font-semibold text-slate-900 dark:text-white">Sales</p>
                    <p class="mt-1 text-sm text-slate-600 group-hover:text-brand-700 dark:text-slate-300 dark:group-hover:text-brand-300">
                        {{ config('brand.sales_email') }}
                    </p>
                </a>
            @endif

            @if (config('brand.support_phone'))
                <div class="rounded-2xl border border-slate-200 p-6 dark:border-slate-800">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <x-icon name="clock" class="h-5 w-5" />
                    </span>
                    <p class="mt-4 font-semibold text-slate-900 dark:text-white">Phone</p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ config('brand.support_phone') }}</p>
                </div>
            @endif

            @if (config('brand.address'))
                <div class="rounded-2xl border border-slate-200 p-6 dark:border-slate-800">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <x-icon name="building" class="h-5 w-5" />
                    </span>
                    <p class="mt-4 font-semibold text-slate-900 dark:text-white">{{ config('brand.legal_name') }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ config('brand.address') }}</p>
                </div>
            @endif
        </div>

        <p class="mt-8 text-sm text-slate-500 dark:text-slate-400">
            Already a customer with a problem? Sign in first — the notifications bell in your workspace usually
            explains what is wrong before you have to ask.
        </p>
    </div>

</x-layouts.public>
