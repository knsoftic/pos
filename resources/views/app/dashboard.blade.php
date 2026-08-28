<x-layouts.app title="Dashboard">

    {{-- The dashboard is where a refused action lands (PermissionDeniedException
         redirects back, and back is usually here), so it must be able to say why. --}}
    <x-flash />

    {{-- Welcome banner --}}
    <div class="mb-6 overflow-hidden rounded-2xl bg-gradient-to-r from-brand-600 to-brand-500 p-6 text-white shadow-sm sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-brand-100">
                    {{ $business->name }}
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight">
                    Assalam-o-Alaikum, {{ str($user->name)->before(' ') }} 👋
                </h2>
                <p class="mt-1 max-w-xl text-sm text-brand-100/90">
                    Aapka workspace tayyar hai — team, branches, counters, products aur inventory sab live hain.
                    POS aur reports agle phases mein aayenge.
                </p>
            </div>
            <div class="flex flex-col items-start gap-2 sm:items-end">
                <span class="badge bg-white/15 text-white ring-1 ring-white/25">
                    {{ ucfirst($business->status) }} · {{ $business->timezone }}
                </span>
                <span class="text-xs text-brand-100/80">Catalogue &amp; inventory live</span>
            </div>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                [
                    'label' => 'Team members',
                    'value' => number_format($stats['staff_count']),
                    'meta' => $stats['owner_count'].' owner'.($stats['owner_count'] === 1 ? '' : 's'),
                    'icon' => 'employees',
                    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                ],
                [
                    'label' => "Today's Sales",
                    'value' => '—',
                    'meta' => 'POS module — Phase 7',
                    'icon' => 'sales',
                    'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
                ],
                [
                    // NULL from the controller means "not yours to see" — shown
                    // as a lock rather than a zero, which would be a lie.
                    'label' => 'Products',
                    'value' => $stats['products_count'] === null ? '—' : number_format($stats['products_count']),
                    'meta' => $stats['products_count'] === null
                        ? 'Hidden for your role'
                        : ($stats['products_inactive'] > 0
                            ? $stats['products_inactive'].' inactive'
                            : 'all active'),
                    'icon' => 'products',
                    'tint' => 'text-violet-600 bg-violet-50 dark:bg-violet-500/10',
                    'href' => $stats['products_count'] === null ? null : route('app.products.index'),
                ],
                [
                    'label' => 'Low Stock',
                    'value' => $stats['low_stock_count'] === null ? '—' : number_format($stats['low_stock_count']),
                    'meta' => match (true) {
                        $stats['low_stock_count'] === null => 'Inventory not on your plan',
                        $stats['low_stock_count'] === 0 => 'nothing needs reordering',
                        default => 'at or under threshold',
                    },
                    'icon' => 'alert',
                    'tint' => $stats['low_stock_count'] > 0
                        ? 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'
                        : 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
                    'href' => $stats['low_stock_count'] === null
                        ? null
                        : route('app.inventory.index', ['status' => 'low']),
                ],
            ];
        @endphp
        @foreach ($cards as $c)
            {{-- A card that stands for a live module is a link to it: the number
                 is only useful if you can act on it (#195). --}}
            @php $href = $c['href'] ?? null; @endphp

            <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
                @class([
                    'card block p-5 transition-shadow hover:shadow-md',
                    'hover:border-brand-300 dark:hover:border-brand-500/40' => $href,
                ])>
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $c['label'] }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{{ $c['value'] }}</p>
                        <p class="mt-1 truncate text-xs text-slate-400">{{ $c['meta'] }}</p>
                    </div>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $c['tint'] }}">
                        <x-icon :name="$c['icon']" class="h-5 w-5" />
                    </span>
                </div>
            </{{ $href ? 'a' : 'div' }}>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Team (real tenant-scoped data) --}}
        <div class="card overflow-hidden lg:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">Your team</h3>
                <span class="text-xs text-slate-400">Sirf aapke business ka data</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">Name</th>
                            <th class="px-5 py-3 font-medium">Email</th>
                            <th class="px-5 py-3 font-medium">Role</th>
                            <th class="px-5 py-3 font-medium">Last login</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($team as $member)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-500/20 dark:text-brand-300">
                                            {{ strtoupper(mb_substr($member->name, 0, 1)) }}
                                        </span>
                                        <span class="font-medium text-slate-900 dark:text-white">{{ $member->name }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $member->email }}</td>
                                <td class="px-5 py-3">
                                    {{-- Real role names now that roles exist (#51);
                                         someone with no role is worth flagging, since
                                         they cannot do anything until they get one. --}}
                                    <span @class([
                                        'badge-green' => $member->isOwner(),
                                        'badge-slate' => ! $member->isOwner() && $member->role_id !== null,
                                        'badge-amber' => ! $member->isOwner() && $member->role_id === null,
                                    ])>{{ $member->roleName() }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ $member->last_login_at?->diffForHumans() ?? 'Never' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3 text-xs dark:border-slate-800">
                @can(\App\Support\PermissionRegistry::EMPLOYEES_VIEW)
                    <a href="{{ route('app.employees.index') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">
                        Manage employees &rarr;
                    </a>
                @else
                    <span class="text-slate-400">Ask the business owner if you need to manage the team.</span>
                @endcan
            </div>
        </div>

        {{-- Business info --}}
        <div class="card p-5">
            <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">Business details</h3>
            <dl class="space-y-3 text-sm">
                @php
                    $details = [
                        ['Name', $business->name],
                        ['Identifier', $business->slug],
                        ['Email', $business->email ?: '—'],
                        ['Phone', $business->phone ?: '—'],
                        ['Timezone', $business->timezone],
                        ['Since', $business->created_at?->format('d M Y')],
                    ];
                @endphp
                @foreach ($details as [$label, $value])
                    <div class="flex items-start justify-between gap-3">
                        <dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                        <dd class="truncate text-right font-medium text-slate-900 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                    <x-icon name="shield" class="h-4 w-4 text-emerald-600" />
                    Data isolation active
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Har query automatically aapke business tak mehdood hai — doosre business ka data kabhi nahi dikhega.
                </p>
            </div>
        </div>
    </div>

    {{-- Quick actions.

         An action appears as a real button only when BOTH its module exists and
         this person may use it (#188); otherwise it stays visibly disabled with
         the reason on hover. A button that looks live and then refuses is worse
         than one that never pretended. --}}
    @php
        // Fully-qualified on purpose: a `use` import inside a mid-file @php block
        // is a PHP parse error, because Blade compiles this slot inside a
        // conditional and imports are only legal at file scope.
        $actions = [
            ['label' => 'New Sale', 'icon' => 'plus', 'route' => null, 'permission' => null, 'note' => 'Coming with the POS'],
            ['label' => 'Add Product', 'icon' => 'products', 'route' => 'app.products.create', 'permission' => \App\Support\PermissionRegistry::PRODUCTS_CREATE, 'note' => null],
            ['label' => 'Adjust Stock', 'icon' => 'inventory', 'route' => 'app.inventory.index', 'permission' => \App\Support\PermissionRegistry::INVENTORY_VIEW, 'note' => null],
            ['label' => 'Add Employee', 'icon' => 'employees', 'route' => 'app.employees.create', 'permission' => \App\Support\PermissionRegistry::EMPLOYEES_MANAGE, 'note' => null],
            ['label' => 'Add Customer', 'icon' => 'customers', 'route' => null, 'permission' => null, 'note' => 'Coming with customers'],
            ['label' => 'View Reports', 'icon' => 'reports', 'route' => null, 'permission' => null, 'note' => 'Coming with reports'],
        ];
    @endphp

    <div class="mt-6">
        <h3 class="mb-3 text-sm font-semibold text-slate-500 dark:text-slate-400">Quick actions</h3>
        <div class="flex flex-wrap gap-3">
            @foreach ($actions as $action)
                @php
                    $allowed = $action['route'] !== null
                        && ($action['permission'] === null || $user->can($action['permission']));
                @endphp

                @if ($allowed)
                    <a href="{{ route($action['route']) }}" class="btn-secondary">
                        <x-icon :name="$action['icon']" class="h-4 w-4" /> {{ $action['label'] }}
                    </a>
                @else
                    <button type="button" disabled
                            title="{{ $action['note'] ?? 'Not available for your role' }}"
                            class="btn-secondary cursor-not-allowed opacity-50">
                        <x-icon :name="$action['icon']" class="h-4 w-4" /> {{ $action['label'] }}
                    </button>
                @endif
            @endforeach
        </div>
    </div>

</x-layouts.app>
