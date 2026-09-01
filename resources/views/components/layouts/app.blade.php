@props(['title' => null])

@php
    use App\Support\FeatureRegistry;
    use App\Support\PermissionRegistry;

    $user = auth('web')->user();
    // `currentBusiness` is shared by the SetBusinessTenant middleware.
    $business = $currentBusiness ?? $user?->business;

    // Resolved here rather than in every controller: the sidebar meters, the
    // expiry banner and the feature-gated nav are chrome, and a page should not
    // have to remember to pass them. All three are cheap — the entitlement maps
    // are cached and the usage counts are the same queries the meters need.
    $layoutSubscription = $business !== null
        ? app(\App\Services\SubscriptionService::class)->current($business)
        : null;

    $layoutFeatures = $business !== null
        ? app(\App\Services\FeatureService::class)->all($business)
        : [];

    $layoutMeters = $business !== null
        ? app(\App\Services\PlanLimitService::class)->meters(null, $business)
        : [];

    $impersonation = session(\App\Http\Controllers\Admin\ImpersonationController::SESSION_KEY);

    /*
     | Navigation is filtered by BOTH gates, not just greyed out (#13, #125, #188):
     |
     |   feature    → is it in the plan? A tenant without multi-branch never sees
     |                a Branches link at all.
     |   permission → may THIS person use it? A cashier does not see Employees,
     |                even though the plan includes it.
     |
     | `null` on either means "not gated by that". Dashboard and Billing are
     | ungated on purpose — Billing especially, since it is where an expired
     | tenant goes to fix the problem, whoever they are.
     |
     | This is presentation only. Every one of these routes carries its own
     | `permission:` middleware; hiding a link is a courtesy, not a guard.
     */
    $nav = [
        ['label' => 'Dashboard',    'icon' => 'dashboard',   'route' => 'app.dashboard',       'feature' => null, 'permission' => null],
        ['label' => 'POS',          'icon' => 'pos',         'route' => 'app.pos.index', 'feature' => FeatureRegistry::POS_TERMINAL, 'permission' => PermissionRegistry::POS_OPERATE],
        ['label' => 'Sales',        'icon' => 'sales',       'route' => 'app.sales.index', 'feature' => FeatureRegistry::SALES_INVOICING, 'permission' => PermissionRegistry::SALES_VIEW],
        ['label' => 'Returns',      'icon' => 'refresh',     'route' => 'app.returns.index', 'feature' => FeatureRegistry::SALES_RETURNS, 'permission' => PermissionRegistry::SALES_RETURN],
        ['label' => 'Purchases',    'icon' => 'purchases',   'route' => 'app.purchases.index', 'feature' => FeatureRegistry::PURCHASES_ORDERS, 'permission' => PermissionRegistry::PURCHASES_VIEW],
        ['label' => 'Products',     'icon' => 'products',    'route' => 'app.products.index',  'feature' => null, 'permission' => PermissionRegistry::PRODUCTS_VIEW],
        ['label' => 'Inventory',    'icon' => 'inventory',   'route' => 'app.inventory.index', 'feature' => FeatureRegistry::INVENTORY_STOCK_TRACKING, 'permission' => PermissionRegistry::INVENTORY_VIEW],
        ['label' => 'Transfers',    'icon' => 'refresh',     'route' => 'app.transfers.index', 'feature' => FeatureRegistry::INVENTORY_TRANSFERS, 'permission' => PermissionRegistry::INVENTORY_TRANSFER],
        ['label' => 'Customers',    'icon' => 'customers',   'route' => 'app.customers.index', 'feature' => FeatureRegistry::CUSTOMERS_MANAGEMENT, 'permission' => PermissionRegistry::CUSTOMERS_VIEW],
        ['label' => 'Suppliers',    'icon' => 'suppliers',   'route' => 'app.suppliers.index', 'feature' => FeatureRegistry::PURCHASES_SUPPLIER_LEDGER, 'permission' => PermissionRegistry::SUPPLIERS_VIEW],
        ['label' => 'Expenses',     'icon' => 'expenses',    'route' => 'app.expenses.index', 'feature' => FeatureRegistry::ACCOUNTING_EXPENSES, 'permission' => PermissionRegistry::EXPENSES_VIEW],
        ['label' => 'Other income', 'icon' => 'trending-up', 'route' => 'app.income.index', 'feature' => FeatureRegistry::ACCOUNTING_EXPENSES, 'permission' => PermissionRegistry::EXPENSES_VIEW],
        ['label' => 'Profit & Loss','icon' => 'reports',     'route' => 'app.reports.profit-loss', 'feature' => FeatureRegistry::ACCOUNTING_PROFIT_LOSS, 'permission' => PermissionRegistry::REPORTS_VIEW_PROFIT],
        ['label' => 'Reports',      'icon' => 'reports',     'route' => 'app.reports.index', 'feature' => FeatureRegistry::REPORTS_BASIC, 'permission' => PermissionRegistry::REPORTS_VIEW],
        ['label' => 'Employees',    'icon' => 'employees',   'route' => 'app.employees.index', 'feature' => FeatureRegistry::TEAM_MULTI_USER, 'permission' => PermissionRegistry::EMPLOYEES_VIEW],
        ['label' => 'Roles',        'icon' => 'shield',      'route' => 'app.roles.index',     'feature' => FeatureRegistry::TEAM_ROLES, 'permission' => PermissionRegistry::ROLES_MANAGE],
        ['label' => 'Branches',     'icon' => 'branches',    'route' => 'app.branches.index',  'feature' => null, 'permission' => PermissionRegistry::BRANCHES_VIEW],
        ['label' => 'POS Counters', 'icon' => 'counter',     'route' => 'app.counters.index',  'feature' => FeatureRegistry::POS_TERMINAL, 'permission' => PermissionRegistry::POS_COUNTERS_MANAGE],
        ['label' => 'Billing',      'icon' => 'credit-card', 'route' => 'app.billing.index',   'feature' => null, 'permission' => null],
        ['label' => 'Settings',     'icon' => 'settings',    'route' => 'app.settings.index', 'feature' => null, 'permission' => PermissionRegistry::SETTINGS_MANAGE],
    ];

    $nav = array_values(array_filter($nav, function ($item) use ($layoutFeatures, $user) {
        $featureOk = $item['feature'] === null || ($layoutFeatures[$item['feature']] ?? false);
        $permissionOk = $item['permission'] === null || (bool) $user?->can($item['permission']);

        return $featureOk && $permissionOk;
    }));

    // Only the two or three tightest quotas belong in the sidebar; the billing
    // page shows every one of them.
    $sidebarMeters = collect($layoutMeters)
        ->reject(fn ($meter) => $meter['unlimited'])
        ->sortByDesc('percent')
        ->take(3);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The tenant's OWN name leads here, not the vendor's: this is their
         workspace. See the note in config/brand.php. --}}
    <title>{{ $title ? $title . ' · ' : '' }}{{ $business?->name ?? config('brand.product') }}</title>

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    {{-- Set theme before paint to avoid flash --}}
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
<body class="h-full bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200"
      x-data="{ sidebarOpen: false }">

    {{-- ============ IMPERSONATION BANNER ============ --}}
    {{-- Deliberately loud and always at the very top: an operator who forgets
         they are inside a customer's account can do real damage. #178 --}}
    @if (is_array($impersonation))
        <div class="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-500 px-4 py-2 text-center text-sm font-medium text-amber-950">
            <span class="flex items-center gap-1.5">
                <x-icon name="user-check" class="h-4 w-4" />
                You are signed in as <strong>{{ $impersonation['user_name'] ?? 'a user' }}</strong>
                at <strong>{{ $impersonation['business_name'] ?? 'this business' }}</strong>.
            </span>
            <form method="POST" action="{{ route('impersonation.stop') }}">
                @csrf
                <button type="submit" class="rounded-lg bg-amber-950/10 px-2.5 py-1 text-xs font-semibold underline hover:bg-amber-950/20">
                    Stop impersonating
                </button>
            </form>
        </div>
    @endif

    {{-- Mobile overlay --}}
    <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden" style="display:none"></div>

    {{-- ============ SIDEBAR ============ --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white
                  transition-transform duration-200 dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

        {{-- THE BUSINESS's identity, not the vendor's. The KN Softic mark is
             here as the product tile, but the name that reads first is the
             shop's own — their staff work in their shop, not in ours. --}}
        <a href="{{ route('app.dashboard') }}"
           class="flex h-16 items-center gap-2.5 border-b border-slate-200 px-5 transition-colors hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
            <x-brand.mark class="h-9 w-9" rounded="rounded-xl" />
            <span class="min-w-0 leading-tight">
                <span class="block truncate text-sm font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ $business?->name ?? config('brand.product') }}
                </span>
                <span class="block truncate text-[11px] text-slate-400">
                    {{ $layoutSubscription?->plan?->name ?? config('brand.tagline') }}
                </span>
            </span>
        </a>

        {{-- Nav --}}
        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
            @foreach ($nav as $item)
                @php
                    $isLive = $item['route'] !== null;
                    // Highlight the whole section, not just its index: editing a
                    // branch should still show Branches as the active item.
                    $family = $isLive ? str_replace('.index', '.*', $item['route']) : null;
                    $active = $isLive && request()->routeIs($family);
                @endphp
                <a href="{{ $isLive ? route($item['route']) : '#' }}"
                   @class([
                       'nav-link',
                       'nav-link-active' => $active,
                       'cursor-not-allowed opacity-50' => ! $isLive,
                   ])
                   @if (! $isLive) title="Coming in a later phase" aria-disabled="true" @endif>
                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        {{-- Plan usage (#78) — the real ceilings, not a placeholder --}}
        <div class="border-t border-slate-200 p-4 dark:border-slate-800">
            <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500 dark:text-slate-400">
                    <span>Plan usage</span>
                    <a href="{{ route('app.billing.index') }}" class="text-brand-600 hover:underline dark:text-brand-400">Details</a>
                </div>

                @if ($sidebarMeters->isNotEmpty())
                    <div class="space-y-2.5">
                        @foreach ($sidebarMeters as $meter)
                            <x-meter :meter="$meter" :compact="true" />
                        @endforeach
                    </div>
                @elseif (! empty($layoutMeters))
                    <p class="text-[11px] text-slate-400">Everything on your plan is unlimited.</p>
                @else
                    <p class="text-[11px] text-slate-400">No active plan — quotas do not apply.</p>
                @endif
            </div>

            <div class="mt-3 flex justify-center">
                <x-brand.powered-by />
            </div>
        </div>
    </aside>

    {{-- ============ MAIN ============ --}}
    <div class="flex min-h-full flex-col lg:pl-64">

        {{-- Topbar --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80 sm:px-6">
            <button @click="sidebarOpen = true" class="btn-ghost -ml-2 p-2 lg:hidden" aria-label="Open menu">
                <x-icon name="menu" />
            </button>

            {{-- Global search (wired in Phase 13) --}}
            <div class="relative hidden max-w-md flex-1 sm:block">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input type="search" placeholder="Search products, customers, invoices…"
                       class="input !py-2 !pl-9 bg-slate-50 dark:bg-slate-800/60">
            </div>

            <div class="ml-auto flex items-center gap-1.5">
                {{-- Theme toggle --}}
                <button onclick="toggleTheme()" class="btn-ghost p-2" aria-label="Toggle theme">
                    <x-icon name="moon" class="block h-5 w-5 dark:hidden" />
                    <x-icon name="sun" class="hidden h-5 w-5 dark:block" />
                </button>

                {{-- ─────────────────────── the bell (#76, #77) ─────────────────────
                     Two kinds of thing live in here and behave in opposite ways:
                     an ALERT is a condition and clears itself when the shop fixes
                     it; an ANNOUNCEMENT is a message and can be put away. See
                     TenantNotificationService for why only one of them is
                     dismissible. The dot is drawn only when there is something —
                     a badge that is always lit is a badge nobody looks at. --}}
                @php
                    $notifications = app(\App\Services\TenantNotificationService::class);
                    $notices = $notifications->all();
                @endphp

                <div class="relative" x-data="{ open: false }">
                    <button @click="open = ! open" class="btn-ghost relative p-2" aria-label="Notifications">
                        <x-icon name="bell" />
                        @if ($notices !== [])
                            <span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full ring-2 ring-white dark:ring-slate-900
                                         {{ $notifications->hasUrgent() ? 'bg-rose-500' : 'bg-amber-500' }}"></span>
                        @endif
                    </button>

                    <div x-show="open" x-cloak @click.outside="open = false"
                         class="card absolute right-0 z-30 mt-2 w-80 overflow-hidden p-0 sm:w-96">
                        <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">Notifications</p>
                            <p class="text-xs text-slate-400">
                                {{ $notices === [] ? 'Nothing needs you right now.' : count($notices).' to look at' }}
                            </p>
                        </div>

                        <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                            @forelse (array_slice($notices, 0, 6) as $notice)
                                <div class="flex items-start gap-3 px-4 py-3">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full
                                        {{ ['danger' => 'bg-rose-500', 'warning' => 'bg-amber-500'][$notice['level']] ?? 'bg-brand-500' }}"></span>

                                    <div class="min-w-0 flex-1">
                                        @if ($notice['href'])
                                            <a href="{{ $notice['href'] }}" class="block text-sm font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                                {{ $notice['title'] }}
                                            </a>
                                        @else
                                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $notice['title'] }}</p>
                                        @endif
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $notice['body'] }}</p>
                                    </div>

                                    @if ($notice['dismissible'] ?? false)
                                        <form method="POST" action="{{ route('app.notifications.dismiss', $notice['id']) }}">
                                            @csrf
                                            <button type="submit" class="rounded p-1 text-slate-300 hover:text-slate-600 dark:hover:text-slate-300"
                                                    title="Dismiss">
                                                <x-icon name="x" class="h-3.5 w-3.5" />
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @empty
                                <p class="px-4 py-8 text-center text-sm text-slate-400">All clear.</p>
                            @endforelse
                        </div>

                        @if (count($notices) > 6)
                            <a href="{{ route('app.notifications.index') }}"
                               class="block border-t border-slate-100 px-4 py-2.5 text-center text-sm font-medium text-brand-600 hover:bg-slate-50 dark:border-slate-800 dark:text-brand-400 dark:hover:bg-slate-800/50">
                                See all {{ count($notices) }}
                            </a>
                        @endif
                    </div>
                </div>

                {{-- User menu --}}
                <div class="relative pl-1" x-data="{ open: false }">
                    <button @click="open = !open" class="flex items-center gap-2 rounded-xl p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">
                            {{ strtoupper(mb_substr($user?->name ?? '?', 0, 1)) }}
                        </span>
                        <span class="hidden max-w-32 truncate text-sm font-medium sm:block">{{ $user?->name }}</span>
                        <x-icon name="chevron-down" class="hidden h-4 w-4 text-slate-400 sm:block" />
                    </button>
                    <div x-show="open" x-transition @click.away="open = false" style="display:none"
                         class="card absolute right-0 mt-2 w-56 py-1.5 shadow-lg">
                        <div class="border-b border-slate-100 px-4 py-2 dark:border-slate-800">
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $user?->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $user?->email }}</p>
                            @if ($user?->is_business_owner)
                                <span class="badge-green mt-1.5">Owner</span>
                            @endif
                        </div>
                        <a href="{{ route('app.billing.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
                            <x-icon name="credit-card" class="h-4 w-4" /> Billing &amp; plan
                        </a>
                        <a href="#" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
                            <x-icon name="settings" class="h-4 w-4" /> Settings
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">
                                <x-icon name="logout" class="h-4 w-4" /> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- ============ SUBSCRIPTION BANNERS (#11) ============ --}}
        {{-- Shown on every page, not just billing: a warning the customer has to
             go looking for is a warning that arrives after the lockout. --}}
        <x-subscription-banner
            :subscription="$layoutSubscription"
            :read-only="$subscriptionReadOnly ?? false"
            :expired="$subscriptionExpired ?? false" />

        {{-- Page header --}}
        @if ($title)
            <div class="border-b border-slate-200 bg-white px-4 py-5 dark:border-slate-800 dark:bg-slate-900 sm:px-6">
                <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>
            </div>
        @endif

        {{-- Content --}}
        <main class="flex-1 p-4 sm:p-6">
            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 px-4 py-4 dark:border-slate-800 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs text-slate-400">
                <span>{{ $business?->name }}</span>
                <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <a href="mailto:{{ config('brand.support_email') }}"
                       class="transition-colors hover:text-brand-600 dark:hover:text-brand-300">Support</a>
                    <span class="opacity-70">v{{ config('brand.version') }}</span>
                    <x-brand.powered-by />
                </span>
            </div>
        </footer>
    </div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
