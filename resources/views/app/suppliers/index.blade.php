<x-layouts.app title="Suppliers">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @php
            $cards = [
                [
                    'label' => 'You owe',
                    'value' => number_format($totals['payable'], 2),
                    'meta' => $totals['owed_count'].' '.Str::plural('account', $totals['owed_count']).' with a balance',
                    'icon' => 'suppliers',
                    'tint' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10',
                ],
                [
                    'label' => 'Paid in advance',
                    'value' => number_format($totals['advances'], 2),
                    'meta' => 'sitting with suppliers',
                    'icon' => 'credit-card',
                    'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10',
                ],
                [
                    'label' => 'On file',
                    'value' => number_format($suppliers->total()),
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
        <form method="GET" action="{{ route('app.suppliers.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="Name, code, contact or phone" class="input" />
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
                    <option value="owed" @selected($filters['balance'] === 'owed')>You owe them</option>
                    <option value="advance" @selected($filters['balance'] === 'advance')>Paid in advance</option>
                </select>
            </div>

            <div class="sm:col-span-4 flex items-center gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                @if (array_filter($filters))
                    <a href="{{ route('app.suppliers.index') }}" class="btn btn-ghost">Clear</a>
                @endif
                @can(\App\Support\PermissionRegistry::SUPPLIERS_MANAGE)
                    <a href="{{ route('app.suppliers.create') }}" class="btn btn-primary ml-auto">
                        <x-icon name="plus" class="h-4 w-4" /> New supplier
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
                        <th class="px-5 py-3 font-medium">Supplier</th>
                        <th class="px-5 py-3 font-medium">Contact</th>
                        <th class="px-5 py-3 font-medium">Terms</th>
                        <th class="px-5 py-3 text-right font-medium">Balance</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($suppliers as $supplier)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <a href="{{ route('app.suppliers.show', $supplier) }}"
                                   class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $supplier->name }}
                                </a>
                                <p class="text-xs text-slate-400">{{ $supplier->code }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                <p>{{ $supplier->contact_person ?? '—' }}</p>
                                <p class="text-xs text-slate-400">{{ $supplier->phone }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                {{ $supplier->payment_terms_days === null ? '—' : $supplier->payment_terms_days.' days' }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                {{ number_format($supplier->absoluteBalance(), 2) }}
                                <span class="block text-xs font-normal text-slate-400">
                                    {{ match ($supplier->balanceDirection()) {
                                        'settled' => 'settled',
                                        'owing' => 'you owe',
                                        default => 'in advance',
                                    } }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $supplier->is_active ? 'badge-green' : 'badge-red' }}">
                                    {{ $supplier->is_active ? 'Active' : 'Blocked' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.suppliers.show', $supplier) }}" class="btn btn-ghost !px-2" title="Statement">
                                        <x-icon name="history" class="h-4 w-4" />
                                    </a>
                                    @can(\App\Support\PermissionRegistry::SUPPLIERS_MANAGE)
                                        <a href="{{ route('app.suppliers.edit', $supplier) }}" class="btn btn-ghost !px-2" title="Edit">
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
                                    No suppliers yet — add the people you buy from.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($suppliers->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $suppliers->links() }}</div>
        @endif
    </div>

</x-layouts.app>
