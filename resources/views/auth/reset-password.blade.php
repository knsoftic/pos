<x-layouts.guest title="Reset password" icon="lock" subtitle="Choose a new password">

    @if ($errors->any())
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4" x-data="{ show: false }">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="label">Email</label>
            <div class="relative">
                <x-icon name="mail" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="email" name="email" type="email" autocomplete="username"
                       value="{{ old('email', $email) }}" required
                       class="input !pl-9">
            </div>
        </div>

        <div>
            <label for="password" class="label">New password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" autocomplete="new-password"
                       required class="input !pl-9 !pr-10" placeholder="••••••••">
                <button type="button" @click="show = !show" tabindex="-1"
                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                        :aria-label="show ? 'Hide password' : 'Show password'">
                    <x-icon x-show="!show" name="eye" class="h-4 w-4" />
                    <x-icon x-show="show" name="eye-off" class="h-4 w-4" style="display:none" />
                </button>
            </div>
            <x-password-hint />
        </div>

        <div>
            <label for="password_confirmation" class="label">Confirm new password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" required class="input !pl-9" placeholder="••••••••">
            </div>
        </div>

        <button type="submit" class="btn-primary w-full">
            Reset password
            <x-icon name="arrow-right" class="h-4 w-4" />
        </button>
    </form>

</x-layouts.guest>
