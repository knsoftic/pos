@props(['title' => 'Sign in', 'icon' => 'pos', 'subtitle' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The sign-in screens are KN Softic's own surface, so the product name
         leads here — unlike /app, where the tenant's business name does. --}}
    <title>{{ $title }} · {{ config('brand.product') }}</title>
    <meta name="description" content="{{ config('brand.description') }}">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <script>
        (function () {
            const t = localStorage.theme;
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
        function toggleTheme() {
            const dark = document.documentElement.classList.toggle('dark');
            localStorage.theme = dark ? 'dark' : 'light';
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark } }));
        }
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">

    {{-- Ambient background --}}
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -left-40 -top-40 h-96 w-96 rounded-full bg-brand-500/10 blur-3xl"></div>
        <div class="absolute -bottom-40 -right-40 h-96 w-96 rounded-full bg-brand-500/10 blur-3xl"></div>
    </div>

    {{-- Theme toggle --}}
    <button onclick="toggleTheme()"
            class="btn-ghost fixed right-4 top-4 p-2"
            aria-label="Toggle theme">
        <x-icon name="moon" class="block h-5 w-5 dark:hidden" />
        <x-icon name="sun" class="hidden h-5 w-5 dark:block" />
    </button>

    <div class="flex min-h-full flex-col items-center justify-center px-4 py-10 sm:py-12">
        <div class="w-full max-w-sm">
            {{-- Brand --}}
            <div class="mb-8 flex flex-col items-center text-center">
                <x-brand.mark class="h-14 w-14" rounded="rounded-2xl" />

                <h1 class="mt-4 text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                    KN<span class="font-medium text-slate-500 dark:text-slate-400">&nbsp;Softic</span>
                </h1>

                <p class="mt-1 text-sm font-medium text-brand-700 dark:text-brand-300">
                    {{ config('brand.tagline') }}
                </p>

                @if ($subtitle)
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
                @endif
            </div>

            {{-- Card --}}
            <div class="card p-6 sm:p-8">
                {{ $slot }}
            </div>

            @isset($footer)
                <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ $footer }}</p>
            @endisset

            <x-brand.footer class="mt-8" />
        </div>
    </div>

    @livewireScripts
</body>
</html>
