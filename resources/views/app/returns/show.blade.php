<x-layouts.app :title="$return->reference">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.returns.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to returns
        </a>

        <a href="{{ route('app.sales.show', $return->sale_id) }}" class="btn btn-secondary">
            <x-icon name="sales" class="h-4 w-4" /> The original sale
        </a>
    </div>

    <div class="card mb-5 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ $return->reference }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $return->reason }}</p>
            </div>

            <dl class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-400">Against</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $return->sale?->invoice_no }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Customer</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">
                        @if ($return->customer)
                            <a href="{{ route('app.customers.show', $return->customer) }}" class="hover:text-brand-700 dark:hover:text-brand-300">
                                {{ $return->customer->name }}
                            </a>
                        @else
                            Walk-in
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Date</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $return->return_date?->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Taken by</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $return->user?->name ?? $return->user_name }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">What came back</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Only what was fit to sell went back on the shelf — the rest was written off.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">Item</th>
                            <th class="px-5 py-3 text-right font-medium">Qty</th>
                            <th class="px-5 py-3 text-right font-medium">Price</th>
                            <th class="px-5 py-3 font-medium">Shelf</th>
                            <th class="px-5 py-3 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($return->items as $item)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $item->description }}</p>
                                    <p class="text-xs text-slate-400">{{ $item->product?->sku }}</p>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ rtrim(rtrim(number_format((float) $item->quantity, 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ number_format((float) $item->unit_price, 2) }}
                                </td>
                                <td class="px-5 py-3">
                                    @if ($item->restock)
                                        <span class="badge-green">Back on the shelf</span>
                                    @else
                                        <span class="badge-red">Written off</span>
                                        @if ($item->condition_note)
                                            <p class="mt-1 text-xs text-slate-400">{{ $item->condition_note }}</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                    {{ number_format((float) $item->line_total, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-5">
            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">The money</h3>

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Subtotal</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $return->subtotal, 2) }}</dd>
                    </div>
                    @if ((float) $return->tax_total > 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Tax</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $return->tax_total, 2) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                        <dt class="font-semibold text-slate-900 dark:text-white">Total</dt>
                        <dd class="text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format((float) $return->total, 2) }}</dd>
                    </div>
                </dl>

                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                    @if ((float) $return->refunded_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">
                                Handed back
                                @if ($return->refund_method)
                                    <span class="text-xs text-slate-400">({{ Str::headline($return->refund_method) }})</span>
                                @endif
                            </dt>
                            <dd class="tabular-nums font-medium text-rose-600 dark:text-rose-400">
                                {{ number_format((float) $return->refunded_amount, 2) }}
                            </dd>
                        </div>
                    @endif

                    @if ((float) $return->credited_amount > 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Credited to the account</dt>
                            <dd class="tabular-nums font-medium text-emerald-600 dark:text-emerald-400">
                                {{ number_format((float) $return->credited_amount, 2) }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($canSeeProfit)
                <div class="card p-5">
                    <h3 class="font-semibold text-slate-900 dark:text-white">What it took back</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Cost of goods</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $return->cost_total, 2) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                            <dt class="font-semibold text-slate-900 dark:text-white">Profit reversed</dt>
                            <dd class="text-lg font-bold tabular-nums text-rose-600 dark:text-rose-400">
                                −{{ number_format($return->profitReversed(), 2) }}
                            </dd>
                        </div>
                        <p class="text-xs text-slate-400">
                            At the cost that applied when the goods sold, not today's.
                        </p>
                    </dl>
                </div>
            @endif

            @if ($return->notes)
                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Notes</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $return->notes }}</p>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
