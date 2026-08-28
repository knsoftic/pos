@props(['title' => 'Sign in', 'icon' => 'pos', 'subtitle' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ config('app.name', 'POS SaaS') }}</title>

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

    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            {{-- Brand --}}
            <div class="mb-8 flex flex-col items-center text-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-white shadow-lg shadow-brand-600/25">
                    <x-icon :name="$icon" class="h-7 w-7" />
                </div>
                <h1 class="mt-4 text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ config('app.name', 'POS SaaS') }}
                </h1>
                @if ($subtitle)
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
                @endif
            </div>

            {{-- Card --}}
            <div class="card p-6 sm:p-8">
                {{ $slot }}
            </div>

            @isset($footer)
                <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ $footer }}</p>
            @endisset
        </div>
    </div>

    @livewireScripts
</body>
</html>
