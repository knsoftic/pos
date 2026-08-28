<x-layouts.guest title="Forgot password" icon="lock" subtitle="Reset your password">

    @if (session('status'))
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
        Apna email daalein — hum aapko password reset link bhej denge.
    </p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label">Email</label>
            <div class="relative">
                <x-icon name="mail" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="email" name="email" type="email" autocomplete="username"
                       value="{{ old('email') }}" required autofocus
                       class="input !pl-9" placeholder="you@business.com">
            </div>
        </div>

        <button type="submit" class="btn-primary w-full">
            Send reset link
            <x-icon name="arrow-right" class="h-4 w-4" />
        </button>
    </form>

    <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
            Back to sign in
        </a>
    </p>

</x-layouts.guest>
