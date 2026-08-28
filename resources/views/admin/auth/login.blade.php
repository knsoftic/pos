<x-layouts.guest title="Admin sign in" icon="shield" subtitle="SaaS operator console">

    @if ($errors->any())
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.login.store') }}" class="space-y-4" x-data="{ show: false }">
        @csrf

        <div>
            <label for="email" class="label">Admin email</label>
            <div class="relative">
                <x-icon name="mail" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="email" name="email" type="email" autocomplete="username"
                       value="{{ old('email') }}" required autofocus
                       class="input !pl-9" placeholder="admin@company.com">
            </div>
        </div>

        <div>
            <label for="password" class="label">Password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" autocomplete="current-password"
                       required class="input !pl-9 !pr-10" placeholder="••••••••">
                <button type="button" @click="show = !show" tabindex="-1"
                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                        :aria-label="show ? 'Hide password' : 'Show password'">
                    <x-icon x-show="!show" name="eye" class="h-4 w-4" />
                    <x-icon x-show="show" name="eye-off" class="h-4 w-4" style="display:none" />
                </button>
            </div>
        </div>

        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
            <input type="checkbox" name="remember"
                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
            Remember me
        </label>

        <button type="submit" class="btn-primary w-full">
            Sign in to console
            <x-icon name="arrow-right" class="h-4 w-4" />
        </button>
    </form>

</x-layouts.guest>
