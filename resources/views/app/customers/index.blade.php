<x-layouts.app title="Customers">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @php
            $cards = [
                [
                    'label' => 'Owed to you',
                    'value' => number_format($totals['receivable'], 2),
                    'meta' => $totals['owing_count'].' '.Str::plural('account', $totals['owing_count']).' with a balance',
                    'icon' => 'customers',
                    'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10',
                ],
                [
                    'label' => 'Held in credit',
                    'value' => number_format($totals['in_credit'], 2),
                    'meta' => 'deposits and overpayments',
                    'icon' => 'credit-card',
                    'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
                ],
                [
                    'label' => 'On file',
                    'value' => number_format($customers->total()),
                    'meta' => $meter['label'].' on your plan',
                    'icon' => 'building',
                    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                ],
            ];
        @endphp

        @foreach ($cards as $c)
            <div class="card p-5">
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
            </div>
        @endforeach
    </div>

    <div class="card mb-5 p-5">
        <form method="GET" action="{{ route('app.customers.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="Name, code, phone or email" class="input" />
            </div>

            <div>
                <label for="status" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Status</label>
                <select id="status" name="status" class="input">
                    <option value="">Any</option>
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="blocked" @selected($filters['status'] === 'blocked')>Blocked</option>
                </select>
            </div>

            <div>
                <label for="balance" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Balance</label>
                <select id="balance" name="balance" class="input">
                    <option value="">Any</option>
                    <option value="owing" @selected($filters['balance'] === 'owing')>Owing</option>
                    <option value="credit" @selected($filters['balance'] === 'credit')>In credit</option>
                    <option value="over_limit" @selected($filters['balance'] === 'over_limit')>Over their limit</option>
                </select>
            </div>

            <div class="sm:col-span-4 flex items-center gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                @if (array_filter($filters))
                    <a href="{{ route('app.customers.index') }}" class="btn btn-ghost">Clear</a>
                @endif
                @can(\App\Support\PermissionRegistry::CUSTOMERS_MANAGE)
                    <a href="{{ route('app.customers.create') }}" class="btn btn-primary ml-auto">
                        <x-icon name="plus" class="h-4 w-4" /> New customer
                    </a>
                @endcan
            </div>
        </form>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Customer</th>
                        <th class="px-5 py-3 font-medium">Contact</th>
                        <th class="px-5 py-3 text-right font-medium">Credit limit</th>
                        <th class="px-5 py-3 text-right font-medium">Balance</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($customers as $customer)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <a href="{{ route('app.customers.show', $customer) }}"
                                   class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $customer->name }}
                                </a>
                                <p class="text-xs text-slate-400">{{ $customer->code }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                <p>{{ $customer->phone ?? '—' }}</p>
                                <p class="text-xs text-slate-400">{{ $customer->city }}</p>
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                @if ($customer->hasUnlimitedCredit())
                                    <span class="badge-brand">Unlimited</span>
                                @elseif ((float) $customer->credit_limit === 0.0)
                                    <span class="text-slate-400">Cash only</span>
                                @else
                                    {{ number_format((float) $customer->credit_limit, 2) }}
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium {{ $customer->isOverLimit() ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                {{ number_format($customer->absoluteBalance(), 2) }}
                                <span class="block text-xs font-normal text-slate-400">
                                    {{ match ($customer->balanceDirection()) {
                                        'settled' => 'settled',
                                        'owing' => 'owes you',
                                        default => 'in credit',
                                    } }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $customer->is_active ? 'badge-green' : 'badge-red' }}">
                                    {{ $customer->is_active ? 'Active' : 'Blocked' }}
                                </span>
                                @if ($customer->isOverLimit())
                                    <span class="badge-amber mt-1 block w-fit">Over limit</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.customers.show', $customer) }}" class="btn btn-ghost !px-2" title="Statement">
                                        <x-icon name="history" class="h-4 w-4" />
                                    </a>
                                    @can(\App\Support\PermissionRegistry::CUSTOMERS_MANAGE)
                                        <a href="{{ route('app.customers.edit', $customer) }}" class="btn btn-ghost !px-2" title="Edit">
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    No customers yet. A shop can sell without them — add one when you need an account.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($customers->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $customers->links() }}</div>
        @endif
    </div>

</x-layouts.app>
