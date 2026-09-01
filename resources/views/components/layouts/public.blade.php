@props([
    'title' => null,
    'description' => null,
])

@php
    /*
    | The marketing site's frame (#106, #107).
    |
    | ⚠️ THIS IS THE VENDOR'S SURFACE, so it carries the BRAND — not a business
    | name. Everything it prints comes from config('brand.*'), which the
    | operator overrides in /admin/settings, so a white-label deployment gets
    | its own site without a single template change (#111).
    */
    $brand = config('brand.name');
    $product = config('brand.product');

    $pageTitle = $title ? $title.' · '.$product : $product.' — '.config('brand.tagline');
    $meta = $description ?: config('brand.description');

    $nav = [
        ['label' => 'Features', 'href' => route('page', 'features'), 'active' => request()->is('features')],
        ['label' => 'POS', 'href' => route('page', 'pos'), 'active' => request()->is('pos')],
        ['label' => 'Inventory', 'href' => route('page', 'inventory'), 'active' => request()->is('inventory')],
        ['label' => 'Reports', 'href' => route('page', 'reports'), 'active' => request()->is('reports')],
        ['label' => 'Pricing', 'href' => route('pricing'), 'active' => request()->is('pricing')],
        ['label' => 'FAQ', 'href' => route('faq'), 'active' => request()->is('faq')],
    ];

    $canRegister = app(\App\Services\RegistrationService::class)->isOpen();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $meta }}">

    {{-- Enough for a link to look right when somebody pastes it into a chat —
         which is how most people first see a product like this (#106). --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $meta }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- ⚠️ Alpine ships INSIDE Livewire in this project (v4 bundles it), so a
         layout without @livewireScripts has no Alpine — and every x-data on the
         page silently does nothing. The pricing toggle and the mobile menu are
         Alpine, so the public site needs it too. --}}
    @livewireStyles
</head>

<body class="min-h-full bg-white font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">


    {{-- Before anything else: a browser that cannot render the app has to be told so (#180). --}}
    <x-browser-notice />

    {{-- ─────────────────────────────── header ─────────────────────────────── --}}
    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/85 backdrop-blur dark:border-slate-800 dark:bg-slate-950/85">
        <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3.5 sm:px-6">
            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
                <x-brand.mark class="h-9 w-9" />
                <span class="text-base font-bold tracking-tight text-slate-900 dark:text-white">{{ $brand }}</span>
            </a>

            <nav class="ml-4 hidden items-center gap-1 lg:flex">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}"
                       class="rounded-lg px-3 py-2 text-sm font-medium transition
                              {{ $item['active']
                                    ? 'text-brand-700 dark:text-brand-300'
                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <button onclick="toggleTheme()" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="Toggle theme">
                    <x-icon name="moon" class="block h-5 w-5 dark:hidden" />
                    <x-icon name="sun" class="hidden h-5 w-5 dark:block" />
                </button>

                <a href="{{ route('login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-900 sm:block dark:text-slate-300 dark:hover:text-white">
                    Sign in
                </a>

                @if ($canRegister)
                    <a href="{{ route('register') }}" class="btn btn-primary !py-2 text-sm">Start free</a>
                @else
                    <a href="{{ route('contact') }}" class="btn btn-primary !py-2 text-sm">Get in touch</a>
                @endif

                <button @click="open = ! open" class="rounded-lg p-2 text-slate-500 lg:hidden dark:text-slate-400" aria-label="Menu">
                    <x-icon name="menu" class="h-5 w-5" />
                </button>
            </div>
        </div>

        <div x-show="open" x-cloak class="border-t border-slate-200 lg:hidden dark:border-slate-800">
            <nav class="mx-auto max-w-6xl space-y-1 px-4 py-3 sm:px-6">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                        {{ $item['label'] }}
                    </a>
                @endforeach
                <a href="{{ route('login') }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                    Sign in
                </a>
            </nav>
        </div>
    </header>

    <main>
        <x-flash />
        {{ $slot }}
    </main>

    {{-- ─────────────────────────────── footer ─────────────────────────────── --}}
    <footer class="border-t border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900/50">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <div class="grid grid-cols-2 gap-8 md:grid-cols-4">
                <div class="col-span-2 md:col-span-1">
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                        <x-brand.mark class="h-8 w-8" />
                        <span class="font-bold text-slate-900 dark:text-white">{{ $brand }}</span>
                    </a>
                    <p class="mt-3 max-w-xs text-sm text-slate-500 dark:text-slate-400">{{ config('brand.tagline') }}</p>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Product</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach (array_slice($nav, 0, 5) as $item)
                            <li><a href="{{ $item['href'] }}" class="text-slate-600 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Company</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li><a href="{{ route('faq') }}" class="text-slate-600 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300">FAQ</a></li>
                        <li><a href="{{ route('contact') }}" class="text-slate-600 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300">Contact</a></li>
                        @if (config('brand.website'))
                            <li><a href="{{ config('brand.website') }}" rel="noopener" class="text-slate-600 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300">{{ config('brand.website_label') ?: 'Website' }}</a></li>
                        @endif
                    </ul>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Get started</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li><a href="{{ route('login') }}" class="text-slate-600 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300">Sign in</a></li>
                        @if ($canRegister)
                            <li><a href="{{ route('register') }}" class="text-slate-600 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300">Create an account</a></li>
                        @endif
                        @if (config('brand.support_email'))
                            <li><a href="mailto:{{ config('brand.support_email') }}" class="text-slate-600 hover:text-brand-700 dark:text-slate-400 dark:hover:text-brand-300">{{ config('brand.support_email') }}</a></li>
                        @endif
                    </ul>
                </div>
            </div>

            <div class="mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-6 text-xs text-slate-400 dark:border-slate-800">
                <p>&copy; {{ config('brand.copyright_since') }}–{{ now()->year }} {{ config('brand.legal_name') }}. All rights reserved.</p>
                <p>{{ $product }} v{{ config('brand.version') }}</p>
            </div>
        </div>
    </footer>

    @livewireScripts
    @stack('scripts')
</body>
</html>
