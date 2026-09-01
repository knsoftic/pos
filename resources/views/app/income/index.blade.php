<x-layouts.app title="Other income">

    <x-flash />

    <div class="card mb-5 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Money in that was not a sale</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Scrap sold to the recycler, a sublet corner, a supplier rebate, an insurance settlement. None of
                    it is revenue — no stock left the shelf — so it sits below the gross profit line and never
                    touches your margin.
                </p>
            </div>

            <div class="text-right">
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Received</p>
                <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    {{ number_format($totals['value'], 2) }}
                </p>
                <p class="text-xs text-slate-400">{{ number_format($totals['count']) }} entries in the period</p>
            </div>
        </div>
    </div>

    <div class="card mb-5 p-5">
        <form method="GET" action="{{ route('app.income.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="Reference, source or note" class="input" />
            </div>
            <div>
                <label for="from" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">From</label>
                <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input" />
            </div>
            <div>
                <label for="to" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">To</label>
                <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input" />
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:col-span-4">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                @if (array_filter($filters))
                    <a href="{{ route('app.income.index') }}" class="btn btn-ghost">Clear</a>
                @endif

                @can(\App\Support\PermissionRegistry::EXPENSES_MANAGE)
                    <a href="{{ route('app.income.create') }}" class="btn btn-primary ml-auto">
                        <x-icon name="plus" class="h-4 w-4" /> Record income
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
                        <th class="px-5 py-3 font-medium">Source</th>
                        <th class="px-5 py-3 font-medium">Received as</th>
                        <th class="px-5 py-3 text-right font-medium">Amount</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($incomes as $income)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $income->reference }}</p>
                                <p class="text-xs text-slate-400">
                                    {{ $income->income_date?->format('d M Y') }} · {{ $income->branch?->name }}
                                </p>
                            </td>
                            <td class="px-5 py-3">
                                <p class="text-slate-600 dark:text-slate-300">{{ $income->source }}</p>
                                @if ($income->note)
                                    <p class="text-xs text-slate-400">{{ $income->note }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <span class="badge-slate">{{ Str::headline((string) $income->payment_method) }}</span>
                                @if ($income->hasAttachment())
                                    <a href="{{ Storage::disk(config('uploads.receipts.disk'))->url($income->attachment_path) }}"
                                       target="_blank" rel="noopener"
                                       class="mt-1 block text-xs text-brand-600 hover:underline dark:text-brand-400"
                                       title="{{ $income->attachment_name }}">
                                        Receipt ({{ $income->attachmentSizeForHumans() }})
                                    </a>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums font-medium text-emerald-600 dark:text-emerald-400">
                                {{ number_format((float) $income->amount, 2) }}
                            </td>
                            <td class="px-5 py-3">
                                @can(\App\Support\PermissionRegistry::EXPENSES_MANAGE)
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('app.income.edit', $income) }}"
                                           class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                           title="Edit">
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </a>
                                        <form method="POST" action="{{ route('app.income.destroy', $income) }}"
                                              data-confirm="Delete {{ $income->reference }}?">
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
                            <td colspan="5" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    Nothing recorded yet.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($incomes->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $incomes->links() }}</div>
        @endif
    </div>

</x-layouts.app>
