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
    // #74: dark mode is a plan feature, so the picker is only drawn where the
    // plan actually includes it.
    $darkModeAllowed = ($layoutFeatures[FeatureRegistry::UI_DARK_MODE] ?? true);

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
        ['label' => 'Suppliers',    'icon' => 'suppliers',   'route' => 'app.suppliers.index', 'feature' => FeatureRegistry::PURCHASES_ORDERS, 'permission' => PermissionRegistry::SUPPLIERS_VIEW],
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

    {{--
        Light / Dark / System (#74), applied BEFORE first paint so nobody sees a
        white flash on the way to a dark screen.

        Three states, not two. "System" is the default and it is a real choice
        rather than an absence of one: somebody whose phone switches at sunset
        expects this to switch with it, and a two-way toggle can only freeze
        them on whichever side they last tapped.

        ⚠️ Gated on the plan (`ui.dark_mode`). Where the feature is off the page
        stays light and the picker is not drawn — an inert control teaches
        people that controls do not work.
    --}}
    <script>
        window.themeAllowed = @json($darkModeAllowed);

        (function () {
            const stored = localStorage.theme;
            const choice = window.themeAllowed ? (stored || 'system') : 'light';
            const dark = choice === 'dark'
                || (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', dark);
        })();

        function applyTheme(choice) {
            if (! window.themeAllowed) {
                choice = 'light';
            }

            localStorage.theme = choice;

            const dark = choice === 'dark'
                || (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', dark);

            // Charts (and anything else colour-aware) re-theme on this event.
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark, choice } }));
        }

        // Follow the operating system while the choice IS "system" — otherwise
        // picking it would only mean "whatever the OS said when the page
        // loaded", which is not what anybody means by it.
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if ((localStorage.theme || 'system') === 'system') {
                applyTheme('system');
            }
        });

        // Kept so older markup and any inline handler still works.
        function toggleTheme() {
            applyTheme(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
        }
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200"
      x-data="{ sidebarOpen: false }">


    {{-- Before anything else: a browser that cannot render the app has to be told so (#180). --}}
    <x-browser-notice />

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

            {{-- ─────────────────────── global search (#75) ──────────────────────
                 Debounced and aborted on every keystroke: without the abort, a
                 fast typist leaves six requests in flight and the dropdown ends
                 up showing whichever one happened to land last — which is
                 usually the results for a prefix they have already deleted. --}}
            <div class="relative hidden max-w-md flex-1 sm:block"
                 x-data="globalSearch()" @keydown.escape.window="close()">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />

                <input type="search" x-model="term" @input.debounce.250ms="run()" @focus="run()"
                       placeholder="Search products, customers, invoices…"
                       autocomplete="off"
                       class="input !py-2 !pl-9 bg-slate-50 dark:bg-slate-800/60">

                <div x-show="open" x-cloak @click.outside="close()"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     class="card absolute left-0 right-0 z-40 mt-2 max-h-96 overflow-y-auto p-0">

                    <template x-if="loading">
                        <p class="px-4 py-6 text-center text-sm text-slate-400">Searching…</p>
                    </template>

                    <template x-if="! loading && count === 0">
                        <p class="px-4 py-6 text-center text-sm text-slate-400">
                            Nothing matches “<span x-text="term"></span>”.
                        </p>
                    </template>

                    <template x-for="(items, group) in groups" :key="group">
                        <div class="border-b border-slate-100 last:border-0 dark:border-slate-800">
                            <p class="px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400"
                               x-text="group"></p>

                            <template x-for="item in items" :key="item.href + item.title">
                                <a :href="item.href"
                                   class="flex items-start gap-3 px-4 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-slate-900 dark:text-white"
                                              x-text="item.title"></span>
                                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400"
                                              x-text="item.meta"></span>
                                    </span>
                                </a>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <div class="ml-auto flex items-center gap-1.5">
                {{-- Theme: light / dark / system (#74) --}}
                @if ($darkModeAllowed)
                    <div class="relative" x-data="{ open: false, choice: localStorage.theme || 'system' }">
                        <button @click="open = ! open" class="btn-ghost p-2" aria-label="Theme">
                            <x-icon name="moon" class="block h-5 w-5 dark:hidden" />
                            <x-icon name="sun" class="hidden h-5 w-5 dark:block" />
                        </button>

                        <div x-show="open" x-cloak @click.outside="open = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             class="card absolute right-0 z-30 mt-2 w-40 p-1">
                            @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                                <button type="button"
                                        @click="choice = @js($value); applyTheme(choice); open = false"
                                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                                    {{ $label }}
                                    {{-- ⚠️ A Blade directive inside a COMPONENT
                                         attribute is not compiled — the tag
                                         would carry the literal text
                                         `choice === @js(...)` and Alpine would
                                         never match. `:attr` evaluates the PHP
                                         and passes the result, which is the
                                         only way to build an Alpine expression
                                         on a component. --}}
                                    <x-icon name="check" class="h-4 w-4 text-brand-600"
                                            :x-show="'choice === '.json_encode($value)" x-cloak />
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

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

        {{-- ─────────────────────────── page header (#164) ───────────────────
             The breadcrumb is DERIVED from the nav item that is currently
             active, not declared per screen. Sixty screens each passing their
             own trail would mean sixty places to get it wrong, and the first
             one somebody forgot would silently show nothing. --}}
        @if ($title)
            @php
                // The same family match the sidebar uses to highlight itself,
                // so the trail and the highlight can never disagree.
                $section = collect($nav)->first(function ($item) {
                    return $item['route'] !== null
                        && request()->routeIs(str_replace('.index', '.*', $item['route']));
                });
                $isSection = $section && $section['label'] === $title;
            @endphp

            <div class="border-b border-slate-200 bg-white px-4 py-5 dark:border-slate-800 dark:bg-slate-900 sm:px-6">
                @if ($section && ! $isSection)
                    <nav class="mb-1.5 flex items-center gap-1.5 text-xs text-slate-400" aria-label="Breadcrumb">
                        <a href="{{ route('app.dashboard') }}" class="hover:text-slate-600 dark:hover:text-slate-300">Dashboard</a>
                        <span aria-hidden="true">/</span>
                        <a href="{{ route($section['route']) }}" class="hover:text-slate-600 dark:hover:text-slate-300">{{ $section['label'] }}</a>
                        <span aria-hidden="true">/</span>
                        <span class="text-slate-500 dark:text-slate-400" aria-current="page">{{ $title }}</span>
                    </nav>
                @endif

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

    <script>
        /*
         | The top-bar search (#75).
         |
         | The abort controller is the important part: every keystroke cancels
         | the request before it, so the dropdown can only ever show the results
         | for what is currently in the box. Without it a fast typist gets
         | whichever response happened to arrive last.
         */
        function globalSearch() {
            return {
                term: '',
                groups: {},
                count: 0,
                open: false,
                loading: false,
                controller: null,

                close() {
                    this.open = false;
                },

                run() {
                    const term = this.term.trim();

                    if (term.length < 2) {
                        this.close();
                        return;
                    }

                    this.controller?.abort();
                    this.controller = new AbortController();

                    this.open = true;
                    this.loading = true;

                    fetch(`{{ route('app.search') }}?q=${encodeURIComponent(term)}`, {
                        headers: { 'Accept': 'application/json' },
                        signal: this.controller.signal,
                    })
                        .then((r) => r.json())
                        .then((data) => {
                            this.groups = data.groups ?? {};
                            this.count = data.count ?? 0;
                            this.loading = false;
                        })
                        .catch((e) => {
                            // An aborted request is the normal case, not a fault.
                            if (e.name !== 'AbortError') {
                                this.loading = false;
                                this.count = 0;
                                this.groups = {};
                            }
                        });
                },
            };
        }
    </script>

    <x-confirm-dialog />

    @livewireScripts
    @stack('scripts')
</body>
</html>
