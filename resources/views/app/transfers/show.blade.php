<x-layouts.app :title="$transfer->reference">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.transfers.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to transfers
        </a>
        <span class="{{ $transfer->status->badgeClass() }}">{{ $transfer->status->label() }}</span>
    </div>

    {{-- A shortfall is the one thing on this screen that must not be missable:
         stock left one shelf and never reached the other. --}}
    @if ($transfer->hasShortfall())
        <div class="card mb-5 border-rose-200 bg-rose-50 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-400">
                    <x-icon name="alert" class="h-5 w-5" />
                </span>
                <div>
                    <p class="font-semibold text-rose-800 dark:text-rose-300">
                        {{ rtrim(rtrim(number_format($transfer->shortfall(), 4), '0'), '.') }} never arrived
                    </p>
                    <p class="mt-0.5 text-sm text-rose-700/90 dark:text-rose-300/80">
                        These units left {{ $transfer->fromBranch?->name }} and were not counted in at
                        {{ $transfer->toBranch?->name }}. The ledger shows exactly that — nothing has been quietly
                        reconciled.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------- summary --}}
        <div class="space-y-5">
            <div class="card p-5">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">{{ $transfer->reference }}</h3>
                <p class="mt-1 text-xs text-slate-400">{{ $transfer->status->description() }}</p>

                <div class="mt-4 flex items-center gap-2 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <span class="font-medium text-slate-800 dark:text-slate-200">{{ $transfer->fromBranch?->name }}</span>
                    <x-icon name="arrow-right" class="h-4 w-4 text-slate-400" />
                    <span class="font-medium text-slate-800 dark:text-slate-200">{{ $transfer->toBranch?->name }}</span>
                </div>

                <dl class="mt-4 space-y-2.5 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Sent quantity</dt>
                        <dd class="font-medium tabular-nums text-slate-900 dark:text-white">
                            {{ rtrim(rtrim(number_format($transfer->totalSent(), 4), '0'), '.') }}
                        </dd>
                    </div>

                    @if ($transfer->totalReceived() !== null)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Received</dt>
                            <dd class="font-medium tabular-nums {{ $transfer->hasShortfall() ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ rtrim(rtrim(number_format($transfer->totalReceived(), 4), '0'), '.') }}
                            </dd>
                        </div>
                    @endif

                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Drafted by</dt>
                        <dd class="text-right text-slate-700 dark:text-slate-300">
                            {{ $transfer->creator?->name ?? '—' }}
                            <span class="block text-xs text-slate-400">{{ $transfer->created_at?->format('d M Y H:i') }}</span>
                        </dd>
                    </div>

                    @if ($transfer->sent_at)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Sent by</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-300">
                                {{ $transfer->sender?->name ?? '—' }}
                                <span class="block text-xs text-slate-400">{{ $transfer->sent_at->format('d M Y H:i') }}</span>
                            </dd>
                        </div>
                    @endif

                    @if ($transfer->received_at)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Received by</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-300">
                                {{ $transfer->receiver?->name ?? '—' }}
                                <span class="block text-xs text-slate-400">{{ $transfer->received_at->format('d M Y H:i') }}</span>
                            </dd>
                        </div>
                    @endif

                    @if ($transfer->cancelled_at)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Cancelled</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-300">
                                {{ $transfer->cancelled_at->format('d M Y H:i') }}
                                <span class="block text-xs text-slate-400">{{ $transfer->cancellation_reason }}</span>
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($transfer->notes)
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-400">
                        {{ $transfer->notes }}
                    </p>
                @endif
            </div>

            {{-- ------------------------------------------------------ actions --}}
            @if ($canSend || $canReceive || $canCancel || $transfer->status->isEditable())
                <div class="card space-y-3 p-5">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Actions</h3>

                    @if ($transfer->status->isEditable())
                        <a href="{{ route('app.transfers.edit', $transfer) }}" class="btn btn-secondary w-full">
                            <x-icon name="pencil" class="h-4 w-4" /> Edit lines
                        </a>
                    @endif

                    @if ($canSend)
                        <form method="POST" action="{{ route('app.transfers.send', $transfer) }}"
                              onsubmit="return confirm('Send this transfer? The stock leaves {{ $transfer->fromBranch?->name }} now.');">
                            @csrf
                            <button type="submit" class="btn btn-primary w-full">
                                <x-icon name="arrow-right" class="h-4 w-4" /> Send the goods
                            </button>
                        </form>
                    @endif

                    @if ($canCancel)
                        <form method="POST" action="{{ route('app.transfers.cancel', $transfer) }}" class="space-y-2">
                            @csrf
                            <input name="reason" type="text" required maxlength="255"
                                   placeholder="Why is it being cancelled?" class="input !py-2 text-sm" />
                            <button type="submit" class="btn btn-secondary w-full text-rose-600 dark:text-rose-400">
                                <x-icon name="ban" class="h-4 w-4" /> Cancel transfer
                            </button>
                            @if ($transfer->status->isInTransit())
                                <p class="text-xs text-slate-400">
                                    The stock goes back on {{ $transfer->fromBranch?->name }}'s shelf, recorded as a movement.
                                </p>
                            @endif
                        </form>
                    @endif

                    @if ($transfer->canBeDeleted())
                        <form method="POST" action="{{ route('app.transfers.destroy', $transfer) }}"
                              onsubmit="return confirm('Delete this draft?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost w-full text-rose-600 dark:text-rose-400">
                                <x-icon name="trash" class="h-4 w-4" /> Delete draft
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>

        {{-- --------------------------------------------------------- lines --}}
        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">
                    {{ $canReceive ? 'Count what arrived' : 'Lines' }}
                </h3>
                @if ($canReceive)
                    <p class="mt-0.5 text-xs text-slate-400">
                        Leave a quantity as it is if the whole line arrived. Change it if it did not — the difference
                        is recorded, not reconciled.
                    </p>
                @endif
            </div>

            <form method="POST" action="{{ route('app.transfers.receive', $transfer) }}">
                @csrf

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-400">
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <th class="px-5 py-3 font-medium">Product</th>
                                <th class="px-5 py-3 text-right font-medium">Sent</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    {{ $canReceive ? 'Arrived' : 'Received' }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($transfer->items as $item)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $item->label() }}</p>
                                        <p class="text-xs text-slate-400">{{ $item->product?->sku }}</p>
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-700 dark:text-slate-300">
                                        {{ rtrim(rtrim(number_format((float) $item->quantity_sent, 4), '0'), '.') }}
                                        <span class="text-xs text-slate-400">{{ $item->product?->unit?->short_name }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        @if ($canReceive)
                                            <input type="number" step="0.0001" min="0"
                                                   name="received[{{ $item->id }}]"
                                                   value="{{ rtrim(rtrim(number_format((float) $item->quantity_sent, 4, '.', ''), '0'), '.') }}"
                                                   class="input !w-28 !py-1.5 text-right text-sm" />
                                        @elseif ($item->quantity_received !== null)
                                            <span class="tabular-nums {{ $item->hasShortfall() ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-slate-700 dark:text-slate-300' }}">
                                                {{ rtrim(rtrim(number_format((float) $item->quantity_received, 4), '0'), '.') }}
                                            </span>
                                            @if ($item->hasShortfall())
                                                <span class="block text-xs text-rose-500">
                                                    −{{ rtrim(rtrim(number_format($item->shortfall(), 4), '0'), '.') }} short
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($canReceive)
                    <div class="border-t border-slate-200 px-5 py-4 dark:border-slate-800">
                        <button type="submit" class="btn btn-primary w-full sm:w-auto">
                            <x-icon name="check" class="h-4 w-4" /> Receive into {{ $transfer->toBranch?->name }}
                        </button>
                    </div>
                @endif
            </form>
        </div>
    </div>

</x-layouts.app>
