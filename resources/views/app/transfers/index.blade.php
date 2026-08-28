<x-layouts.app title="Stock transfers">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">Moving stock between branches</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                A transfer has three steps because a journey has three moments: written down, sent, and counted in.
                While it is in transit the stock is off the source shelf and not yet on the destination's — which is
                exactly where it really is.
            </p>

            @if ($incoming > 0)
                <a href="{{ route('app.transfers.index', ['status' => 'sent']) }}"
                   class="mt-3 inline-flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700 transition-colors hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-400">
                    <x-icon name="clock" class="h-4 w-4" />
                    {{ $incoming }} {{ Str::plural('transfer', $incoming) }} in transit
                </a>
            @endif
        </div>

        <div class="card flex flex-col justify-center p-5">
            <a href="{{ route('app.transfers.create') }}" class="btn btn-primary w-full">
                <x-icon name="plus" class="h-4 w-4" /> New transfer
            </a>
            @if ($drafts > 0)
                <p class="mt-3 text-center text-xs text-slate-400">
                    {{ $drafts }} {{ Str::plural('draft', $drafts) }} not sent yet
                </p>
            @endif
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h3 class="font-semibold text-slate-900 dark:text-white">All transfers</h3>

            <form method="GET" action="{{ route('app.transfers.index') }}">
                <select name="status" class="input !py-1.5 text-xs" onchange="this.form.submit()">
                    <option value="">Any status</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Reference</th>
                        <th class="px-5 py-3 font-medium">Route</th>
                        <th class="px-5 py-3 text-right font-medium">Lines</th>
                        <th class="px-5 py-3 text-right font-medium">Quantity</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($transfers as $transfer)
                        <tr class="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50"
                            onclick="window.location='{{ route('app.transfers.show', $transfer) }}'">
                            <td class="px-5 py-3">
                                <a href="{{ route('app.transfers.show', $transfer) }}"
                                   class="font-medium text-brand-700 hover:underline dark:text-brand-300">
                                    {{ $transfer->reference }}
                                </a>
                                @if ($transfer->hasShortfall())
                                    <span class="badge-red ml-1">Short</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                <span class="inline-flex items-center gap-1.5">
                                    {{ $transfer->fromBranch?->name }}
                                    <x-icon name="arrow-right" class="h-3.5 w-3.5 text-slate-400" />
                                    {{ $transfer->toBranch?->name }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $transfer->items->count() }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-900 dark:text-white">
                                {{ rtrim(rtrim(number_format($transfer->totalSent(), 4), '0'), '.') }}
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $transfer->status->badgeClass() }}">{{ $transfer->status->label() }}</span>
                            </td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                {{ ($transfer->received_at ?? $transfer->sent_at ?? $transfer->created_at)?->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                                No transfers yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($transfers->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                {{ $transfers->links() }}
            </div>
        @endif
    </div>

</x-layouts.app>
