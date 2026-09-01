{{--
    The account statement, in the format a bookkeeper expects (#41, #42):
    date, description, debit, credit, running balance.

    Shared by customers and suppliers because the SHAPE is identical — only the
    heading above it differs, and that belongs to the page, not to the table.
    `debitLabel` / `creditLabel` let each side name its own columns without
    forking this file.
--}}
@props([
    'entries',
    'totals',
    'from' => null,
    'to' => null,
    'action',
    'debitLabel' => 'Debit',
    'creditLabel' => 'Credit',
    'balanceLabel' => 'Balance',
])

<div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
        <div>
            <h3 class="font-semibold text-slate-900 dark:text-white">Statement</h3>
            <p class="mt-0.5 text-xs text-slate-400">
                Oldest first, so the running balance reads downwards. Nothing here is ever edited — a mistake is
                corrected by posting its opposite.
            </p>
        </div>

        <form method="GET" action="{{ $action }}" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="from" class="mb-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">From</label>
                <input id="from" type="date" name="from" value="{{ $from }}" class="input !py-1.5 text-xs" />
            </div>
            <div>
                <label for="to" class="mb-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">To</label>
                <input id="to" type="date" name="to" value="{{ $to }}" class="input !py-1.5 text-xs" />
            </div>
            <button type="submit" class="btn btn-secondary !py-1.5 text-xs">Apply</button>
            @if ($from || $to)
                <a href="{{ $action }}" class="btn btn-ghost !py-1.5 text-xs">Clear</a>
            @endif
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-xs uppercase tracking-wide text-slate-400">
                <tr class="border-b border-slate-100 dark:border-slate-800">
                    <th class="px-5 py-3 font-medium">Date</th>
                    <th class="px-5 py-3 font-medium">Particulars</th>
                    <th class="px-5 py-3 text-right font-medium">{{ $debitLabel }}</th>
                    <th class="px-5 py-3 text-right font-medium">{{ $creditLabel }}</th>
                    <th class="px-5 py-3 text-right font-medium">{{ $balanceLabel }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @if ($from && $totals['opening'] != 0)
                    {{-- A filtered statement that starts at zero does not add up.
                         This is what the account stood at before the window. --}}
                    <tr class="bg-slate-50/60 dark:bg-slate-800/30">
                        <td class="px-5 py-2.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}
                        </td>
                        <td class="px-5 py-2.5 text-xs font-medium text-slate-600 dark:text-slate-300">
                            Brought forward
                        </td>
                        <td colspan="2"></td>
                        <td class="px-5 py-2.5 text-right text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-200">
                            {{ number_format($totals['opening'], 2) }}
                        </td>
                    </tr>
                @endif

                @forelse ($entries as $entry)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                            <p>{{ $entry->entry_date?->format('d M Y') }}</p>
                            @if ($entry->branch)
                                <p class="text-xs text-slate-400">{{ $entry->branch->name }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <span class="{{ $entry->type->badgeClass() }}">{{ $entry->type->label() }}</span>
                            <p class="mt-1 text-slate-700 dark:text-slate-300">{{ $entry->title() }}</p>
                            <p class="text-xs text-slate-400">
                                @if ($entry->reference_no)
                                    Ref {{ $entry->reference_no }} ·
                                @endif
                                @if ($entry->payment_method)
                                    {{ Str::headline($entry->payment_method) }} ·
                                @endif
                                {{ $entry->user?->name ?? 'System' }}
                            </p>
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums {{ $entry->isDebit() ? 'font-medium text-slate-900 dark:text-white' : 'text-slate-300 dark:text-slate-600' }}">
                            {{ $entry->isDebit() ? number_format((float) $entry->debit, 2) : '—' }}
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums {{ ! $entry->isDebit() ? 'font-medium text-emerald-600 dark:text-emerald-400' : 'text-slate-300 dark:text-slate-600' }}">
                            {{ ! $entry->isDebit() ? number_format((float) $entry->credit, 2) : '—' }}
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums font-semibold {{ (float) $entry->balance_after < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-900 dark:text-white' }}">
                            {{ number_format((float) $entry->balance_after, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-slate-400">
                            @if ($from || $to)
                                Nothing on this account in that period.
                            @else
                                Nothing on this account yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>

            @if ($totals['entries'] > 0)
                <tfoot class="border-t-2 border-slate-200 text-sm dark:border-slate-700">
                    <tr>
                        <td colspan="2" class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Period total
                        </td>
                        <td class="px-5 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ number_format($totals['debit'], 2) }}
                        </td>
                        <td class="px-5 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ number_format($totals['credit'], 2) }}
                        </td>
                        <td class="px-5 py-3 text-right font-bold tabular-nums text-slate-900 dark:text-white">
                            {{ number_format($totals['closing'], 2) }}
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($entries->hasPages())
        <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $entries->links() }}</div>
    @endif
</div>
