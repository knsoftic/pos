<x-layouts.guest title="Create your shop"
                 :subtitle="$trialDays ? $trialDays.' days free — no card needed' : 'Set your workspace up in a minute'">

    @if ($errors->any())
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-400">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('register.store') }}" class="space-y-4" x-data="{ show: false }">
        @csrf

        <div>
            <label for="business_name" class="label">Shop name</label>
            <div class="relative">
                <x-icon name="building" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="business_name" name="business_name" type="text" required autofocus maxlength="150"
                       value="{{ old('business_name') }}" class="input !pl-9" placeholder="Karim General Store">
            </div>
            <p class="mt-1 text-xs text-slate-400">This goes on every receipt. You can change it later.</p>
        </div>

        <div>
            <label for="name" class="label">Your name</label>
            <div class="relative">
                <x-icon name="user-check" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="name" name="name" type="text" required maxlength="120" autocomplete="name"
                       value="{{ old('name') }}" class="input !pl-9" placeholder="Karim Ahmed">
            </div>
        </div>

        <div>
            <label for="email" class="label">Email</label>
            <div class="relative">
                <x-icon name="mail" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="email" name="email" type="email" inputmode="email" autocomplete="username" required maxlength="190"
                       value="{{ old('email') }}" class="input !pl-9" placeholder="you@yourshop.com">
            </div>
            <p class="mt-1 text-xs text-slate-400">This is how you will sign in.</p>
        </div>

        <div>
            <label for="phone" class="label">Phone <span class="text-slate-400">(optional)</span></label>
            <input id="phone" name="phone" type="text" maxlength="40" autocomplete="tel"
                   value="{{ old('phone') }}" class="input">
        </div>

        <div>
            <label for="password" class="label">Password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="password" name="password" :type="show ? 'text' : 'password'" autocomplete="new-password"
                       required class="input !pl-9 !pr-10" placeholder="••••••••">
                <button type="button" @click="show = ! show" tabindex="-1"
                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                        :aria-label="show ? 'Hide password' : 'Show password'">
                    <x-icon x-show="! show" name="eye" class="h-4 w-4" />
                    <x-icon x-show="show" name="eye-off" class="h-4 w-4" style="display:none" />
                </button>
            </div>
            <x-password-hint />
        </div>

        <div>
            <label for="password_confirmation" class="label">Confirm password</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'"
                       autocomplete="new-password" required class="input !pl-9" placeholder="••••••••">
            </div>
        </div>

        <label class="flex cursor-pointer items-start gap-2.5 text-sm text-slate-600 dark:text-slate-400">
            <input type="checkbox" name="terms" value="1" @checked(old('terms'))
                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
            <span>
                I agree to the terms of service and privacy policy of {{ config('brand.legal_name') }}.
            </span>
        </label>

        <button type="submit" class="btn btn-primary w-full">
            Create my shop <x-icon name="arrow-right" class="h-4 w-4" />
        </button>

        @if ($plan)
            {{-- ⚠️ Blade's directive regex is \B@, so a directive glued to a
                 word — `days@endif` — is silently NOT compiled and the file
                 dies with "unexpected end of file". The trailing clause is
                 built in PHP so no directive ever touches a letter. --}}
            @php
                $planLine = $trialDays
                    ? ', free for '.$trialDays.' days.'
                    : '.';
            @endphp

            <p class="text-center text-xs text-slate-400">
                You will start on
                <strong class="text-slate-500 dark:text-slate-300">{{ $plan->name }}</strong>{{ $planLine }}
                No card, and nothing renews by itself.
            </p>
        @endif
    </form>

    <p class="mt-6 text-center text-sm text-slate-600 dark:text-slate-400">
        Already have an account?
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Sign in</a>
    </p>

</x-layouts.guest>
