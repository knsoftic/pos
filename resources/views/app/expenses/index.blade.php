<x-layouts.app title="Expenses">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @php
            $days = max(1, count(array_filter([$filters['from'], $filters['to']])) === 2
                ? \Illuminate\Support\Carbon::parse($filters['from'])->diffInDays($filters['to']) + 1
                : now()->day);

            $cards = [
                [
                    'label' => 'Spent',
                    'value' => number_format($totals['value'], 2),
                    'meta' => 'in the filtered period',
                    'icon' => 'expenses',
                    'tint' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10',
                ],
                [
                    'label' => 'Entries',
                    'value' => number_format($totals['count']),
                    'meta' => 'individual records',
                    'icon' => 'note',
                    'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10',
                ],
                [
                    'label' => 'Average a day',
                    'value' => number_format($totals['value'] / $days, 2),
                    'meta' => 'across '.$days.' '.\Illuminate\Support\Str::plural('day', $days),
                    'icon' => 'trending-up',
                    'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10',
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
        <form method="GET" action="{{ route('app.expenses.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-5">
            <div class="sm:col-span-2">
                <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="Reference, payee, bill number or note" class="input" />
            </div>

            <div>
                <label for="category" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Category</label>
                <select id="category" name="category" class="input">
                    <option value="">All</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $filters['category'] === (string) $category->id)>
                            {{ $category->name }}@unless ($category->is_active) (off){{ '' }}@endunless
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="from" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">From</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input" />
            </div>
            <div>
                <label for="to" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">To</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input" />
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:col-span-5">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                @if (array_filter($filters))
                    <a href="{{ route('app.expenses.index') }}" class="btn btn-ghost">Clear</a>
                @endif

                @can(\App\Support\PermissionRegistry::EXPENSES_MANAGE)
                    <a href="{{ route('app.expense-categories.index') }}" class="btn btn-ghost ml-auto">
                        <x-icon name="sliders" class="h-4 w-4" /> Categories
                    </a>
                    <a href="{{ route('app.expenses.create') }}" class="btn btn-primary">
                        <x-icon name="plus" class="h-4 w-4" /> Record an expense
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
                        <th class="px-5 py-3 font-medium">Reference</th>
                        <th class="px-5 py-3 font-medium">Category</th>
                        <th class="px-5 py-3 font-medium">Paid to</th>
                        <th class="px-5 py-3 font-medium">Paid with</th>
                        <th class="px-5 py-3 text-right font-medium">Amount</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($expenses as $expense)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $expense->reference }}</p>
                                <p class="text-xs text-slate-400">
                                    {{ $expense->expense_date?->format('d M Y') }} · {{ $expense->branch?->name }}
                                </p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $expense->category?->name }}</td>
                            <td class="px-5 py-3">
                                <p class="text-slate-600 dark:text-slate-300">{{ $expense->payee ?: '—' }}</p>
                                @if ($expense->bill_no)
                                    <p class="text-xs text-slate-400">Bill {{ $expense->bill_no }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <span class="badge-slate">{{ Str::headline((string) $expense->payment_method) }}</span>
                                @if ($expense->hasAttachment())
                                    <a href="{{ Storage::disk(config('uploads.receipts.disk'))->url($expense->attachment_path) }}"
                                       target="_blank" rel="noopener"
                                       class="mt-1 block text-xs text-brand-600 hover:underline dark:text-brand-400"
                                       title="{{ $expense->attachment_name }}">
                                        Receipt ({{ $expense->attachmentSizeForHumans() }})
                                    </a>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                {{ number_format((float) $expense->amount, 2) }}
                            </td>
                            <td class="px-5 py-3">
                                @can(\App\Support\PermissionRegistry::EXPENSES_MANAGE)
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('app.expenses.edit', $expense) }}"
                                           class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                           title="Edit">
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </a>
                                        <form method="POST" action="{{ route('app.expenses.destroy', $expense) }}"
                                              onsubmit="return confirm('Delete {{ $expense->reference }}? The cash it took out of the till goes back.')">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 dark:hover:text-rose-400"
                                                    title="Delete">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    Nothing recorded yet. Rent, wages and electricity go here — stock does not.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($expenses->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $expenses->links() }}</div>
        @endif
    </div>

</x-layouts.app>
