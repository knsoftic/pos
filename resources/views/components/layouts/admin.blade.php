@props(['title' => null])

@php
    $admin = auth('admin')->user();

    // #179. Resolved here rather than passed by every controller so the bell is
    // present on every operator screen. Deliberately NOT cached — see
    // SystemNotificationService: a badge that nags about a problem you already
    // fixed is a badge people learn to ignore.
    $alerts = $admin ? app(\App\Services\SystemNotificationService::class) : null;
    $alertCount = $alerts?->count() ?? 0;

    $nav = [
        ['label' => 'Dashboard',     'icon' => 'dashboard',   'href' => route('admin.dashboard'), 'active' => request()->routeIs('admin.dashboard')],
        ['label' => 'Businesses',    'icon' => 'building',    'href' => route('admin.businesses.index'), 'active' => request()->routeIs('admin.businesses.*')],
        ['label' => 'Subscriptions', 'icon' => 'credit-card', 'href' => route('admin.subscriptions.index'), 'active' => request()->routeIs('admin.subscriptions.*')],
        ['label' => 'Plans',         'icon' => 'products',    'href' => route('admin.plans.index'), 'active' => request()->routeIs('admin.plans.*')],
        ['label' => 'Alerts',        'icon' => 'bell',        'href' => route('admin.notifications.index'), 'active' => request()->routeIs('admin.notifications.*'), 'badge' => $alertCount],
        // Phase 3 (#110 operator settings, #180 admin accounts).
        ['label' => 'Admins',        'icon' => 'shield',      'href' => null, 'active' => false],
        ['label' => 'Settings',      'icon' => 'settings',    'href' => null, 'active' => false],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title . ' · ' : '' }}Operator Console · {{ config('brand.name') }}</title>

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
            // Charts (and anything else colour-aware) re-theme on this event.
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark } }));
        }
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-100 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200"
      x-data="{ sidebarOpen: false }">

    <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden" style="display:none"></div>

    {{-- Sidebar — dark slate to distinguish the operator console from tenant apps --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 text-slate-300
                  transition-transform duration-200 dark:border-r dark:border-slate-800 lg:translate-x-0"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

        {{-- The operator console is KN Softic's own room, so the company name
             leads here rather than any tenant's. --}}
        <a href="{{ route('admin.dashboard') }}"
           class="flex h-16 items-center gap-2.5 border-b border-white/10 px-5 transition-colors hover:bg-white/5">
            <x-brand.mark class="h-9 w-9" rounded="rounded-xl" />
            <span class="min-w-0 leading-tight">
                <span class="block truncate text-sm font-bold tracking-tight text-white">
                    KN<span class="font-medium text-slate-400">&nbsp;Softic</span>
                </span>
                <span class="block truncate text-[11px] text-slate-400">Operator Console</span>
            </span>
        </a>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] ?? '#' }}"
                   @class([
                       'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                       'bg-white/10 text-white' => $item['active'],
                       'text-slate-400 hover:bg-white/5 hover:text-white' => ! $item['active'] && $item['href'] !== null,
                       'cursor-not-allowed text-slate-600' => $item['href'] === null,
                   ])
                   @if ($item['href'] === null) title="Coming in a later phase" aria-disabled="true" @endif>
                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                    <span>{{ $item['label'] }}</span>
                    @if (($item['badge'] ?? 0) > 0)
                        <span class="ml-auto rounded-full bg-rose-500 px-2 py-0.5 text-[11px] font-bold text-white">
                            {{ $item['badge'] }}
                        </span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="space-y-1.5 border-t border-white/10 p-4 text-[11px] text-slate-500">
            <p class="font-medium text-slate-400">{{ config('brand.product') }}</p>
            <p>v{{ config('brand.version') }}</p>
            <p>&copy; {{ now()->format('Y') }} {{ config('brand.legal_name') }}</p>
        </div>
    </aside>

    <div class="flex min-h-full flex-col lg:pl-64">

        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80 sm:px-6">
            <button @click="sidebarOpen = true" class="btn-ghost -ml-2 p-2 lg:hidden" aria-label="Open menu">
                <x-icon name="menu" />
            </button>

            <span class="badge-brand">{{ config('brand.name') }} · Operator</span>

            <div class="ml-auto flex items-center gap-1.5">
                {{-- #179 Alert bell. The dropdown is a summary; the page has the detail. --}}
                @if ($alerts)
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="btn-ghost relative p-2"
                                aria-label="{{ $alertCount > 0 ? $alertCount.' system alerts' : 'No system alerts' }}">
                            <x-icon name="bell" class="h-5 w-5" />
                            @if ($alertCount > 0)
                                <span @class([
                                    'absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold text-white',
                                    'bg-rose-500' => $alerts->hasCritical(),
                                    'bg-amber-500' => ! $alerts->hasCritical(),
                                ])>{{ $alertCount > 9 ? '9+' : $alertCount }}</span>
                            @endif
                        </button>

                        <div x-show="open" x-transition @click.away="open = false" style="display:none"
                             class="card absolute right-0 mt-2 w-80 overflow-hidden shadow-lg">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">System alerts</p>
                                @if ($alertCount > 0)
                                    <span class="text-xs text-slate-500">{{ $alertCount }} to review</span>
                                @endif
                            </div>

                            @forelse ($alerts->preview() as $alert)
                                <a href="{{ $alert['url'] ?? route('admin.notifications.index') }}"
                                   class="flex gap-3 border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                                    <x-icon :name="$alert['icon']" @class([
                                        'mt-0.5 h-4 w-4 shrink-0',
                                        'text-rose-500' => $alert['severity'] === 'danger',
                                        'text-amber-500' => $alert['severity'] === 'warning',
                                        'text-slate-400' => $alert['severity'] === 'info',
                                    ]) />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">
                                            {{ $alert['title'] }}
                                            <span class="text-slate-400">({{ $alert['count'] }})</span>
                                        </p>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">{{ $alert['message'] }}</p>
                                    </div>
                                </a>
                            @empty
                                <div class="px-4 py-6 text-center">
                                    <x-icon name="check-circle" class="mx-auto h-6 w-6 text-emerald-500" />
                                    <p class="mt-2 text-sm text-slate-500">Nothing needs attention.</p>
                                </div>
                            @endforelse

                            <a href="{{ route('admin.notifications.index') }}"
                               class="block bg-slate-50 px-4 py-2.5 text-center text-xs font-semibold text-brand-600 hover:bg-slate-100 dark:bg-slate-800/50 dark:text-brand-400 dark:hover:bg-slate-800">
                                View all alerts
                            </a>
                        </div>
                    </div>
                @endif

                <button onclick="toggleTheme()" class="btn-ghost p-2" aria-label="Toggle theme">
                    <x-icon name="moon" class="block h-5 w-5 dark:hidden" />
                    <x-icon name="sun" class="hidden h-5 w-5 dark:block" />
                </button>

                <div class="relative pl-1" x-data="{ open: false }">
                    <button @click="open = !open" class="flex items-center gap-2 rounded-xl p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-800 text-sm font-semibold text-white">
                            {{ strtoupper(substr($admin?->name ?? 'A', 0, 1)) }}
                        </span>
                        <span class="hidden text-sm font-medium sm:block">{{ $admin?->name ?? 'Admin' }}</span>
                        <x-icon name="chevron-down" class="hidden h-4 w-4 text-slate-400 sm:block" />
                    </button>
                    <div x-show="open" x-transition @click.away="open = false" style="display:none"
                         class="card absolute right-0 mt-2 w-48 py-1.5 shadow-lg">
                        <div class="border-b border-slate-100 px-4 py-2 dark:border-slate-800">
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $admin?->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $admin?->email }}</p>
                        </div>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">
                                <x-icon name="logout" class="h-4 w-4" /> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        @if ($title)
            <div class="border-b border-slate-200 bg-white px-4 py-5 dark:border-slate-800 dark:bg-slate-900 sm:px-6">
                <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>
            </div>
        @endif

        <main class="flex-1 p-4 sm:p-6">
            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 px-4 py-4 dark:border-slate-800 sm:px-6">
            <x-brand.footer align="between" :version="true" />
        </footer>
    </div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
