{{--
    Flash + validation feedback, in one place so every screen reports success and
    failure identically (#165, #166).

    ================= WHY SUCCESS FLOATS AND FAILURE DOES NOT =================
    A SUCCESS message is an acknowledgement — "saved", "recorded". The person
    already knows what they did; they need a half-second of confirmation and
    then their screen back. So it becomes a TOAST that dismisses itself.

    Everything else stays INLINE and stays put: a validation summary that faded
    away while somebody was reading it would be actively hostile, and a plan
    limit needs a decision, not a glance. An auto-dismissing error is a message
    the user is invited to miss.

    Reads the session keys the controllers actually set: `success`, `error`,
    `warning`, plus the two structured keys thrown by the entitlement layer
    (`limit_exceeded` / `feature_unavailable`), which carry a hint about what to
    do next rather than just a message.
--}}
@php
    $limitExceeded = session('limit_exceeded');
    $featureUnavailable = session('feature_unavailable');
@endphp

{{-- ─────────────────────────── success: a toast ───────────────────────────── --}}
@if (session('success'))
    <div x-data="{ show: false }"
         x-init="$nextTick(() => show = true); setTimeout(() => show = false, 4500)"
         class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex justify-center px-4 pb-6 sm:justify-end sm:pr-6">
        <div x-show="show" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-2"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl border border-emerald-200 bg-white p-4 text-sm text-emerald-800 shadow-lg dark:border-emerald-500/30 dark:bg-slate-900 dark:text-emerald-300"
             role="status" aria-live="polite">
            <x-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-500" />
            <p class="min-w-0 flex-1">{{ session('success') }}</p>
            <button type="button" @click="show = false"
                    class="shrink-0 rounded p-0.5 text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-200"
                    aria-label="Dismiss">
                <x-icon name="x" class="h-4 w-4" />
            </button>
        </div>
    </div>
@endif

{{-- ──────────────── everything that needs reading: inline ─────────────────── --}}
@if (session('error'))
    <div class="mb-4 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300"
         role="alert">
        <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0" />
        <p>{{ session('error') }}</p>
    </div>
@endif

@if (session('warning'))
    <div class="mb-4 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
         role="alert">
        <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0" />
        <p>{{ session('warning') }}</p>
    </div>
@endif

{{-- Thrown by LimitExceededException — tells the user which quota bit, and by
     how much, instead of a bare "not allowed". #78 --}}
@if (is_array($limitExceeded))
    <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
        <div class="flex items-start gap-3">
            <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold text-amber-900 dark:text-amber-200">
                    {{ $limitExceeded['limit_name'] ?? 'Plan limit' }} reached
                </p>
                <p class="mt-0.5 text-amber-800 dark:text-amber-300">
                    You are using {{ number_format((float) ($limitExceeded['usage'] ?? 0)) }}
                    of {{ number_format((float) ($limitExceeded['limit'] ?? 0)) }}.
                    Upgrade your plan to add more.
                </p>
                @if (\Illuminate\Support\Facades\Route::has('app.billing.plans'))
                    <a href="{{ route('app.billing.plans') }}" class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-amber-900 underline dark:text-amber-200">
                        Compare plans <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                @endif
            </div>
        </div>
    </div>
@endif

{{-- Thrown by FeatureUnavailableException. --}}
@if (is_array($featureUnavailable))
    <div class="mb-4 rounded-2xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-500/30 dark:bg-brand-500/10">
        <div class="flex items-start gap-3">
            <x-icon name="lock" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600 dark:text-brand-400" />
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold text-brand-900 dark:text-brand-200">
                    {{ $featureUnavailable['feature_name'] ?? 'That feature' }} is not in your plan
                </p>
                <p class="mt-0.5 text-brand-800 dark:text-brand-300">Upgrade to unlock it.</p>
            </div>
        </div>
    </div>
@endif

@if ($errors->any())
    <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10" role="alert">
        <div class="flex items-start gap-3">
            <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-rose-600 dark:text-rose-400" />
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold text-rose-900 dark:text-rose-200">
                    {{ trans_choice('Please fix :count problem|Please fix :count problems', $errors->count(), ['count' => $errors->count()]) }}
                </p>
                <ul class="mt-1 list-inside list-disc space-y-0.5 text-rose-800 dark:text-rose-300">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif
