<x-layouts.app :title="$sale->invoice_no">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.sales.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to sales
        </a>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('app.sales.receipt', [$sale, 'reprint' => 1]) }}" target="_blank" class="btn btn-secondary">
                <x-icon name="printer" class="h-4 w-4" /> Receipt
            </a>

            @if ($sale->status->canBeVoided())
                @can(\App\Support\PermissionRegistry::SALES_VOID)
                    <div x-data="{ asking: false }" class="relative">
                        <button type="button" class="btn btn-ghost text-rose-600 dark:text-rose-400" @click="asking = ! asking">
                            <x-icon name="ban" class="h-4 w-4" /> Void
                        </button>

                        <form method="POST" action="{{ route('app.sales.void', $sale) }}"
                              x-show="asking" x-cloak @click.outside="asking = false"
                              class="card absolute right-0 z-20 mt-2 w-80 space-y-2 p-4">
                            @csrf
                            <label for="reason" class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                                Why is this sale being voided?
                            </label>
                            <input id="reason" name="reason" type="text" required maxlength="255"
                                   placeholder="Rung up twice" class="input !py-2 text-sm" />
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                The stock goes back on the shelf and any credit is cleared. The record stays —
                                somebody has the paper copy.
                            </p>
                            <button type="submit" class="btn btn-primary w-full !py-2 text-sm">Void sale</button>
                        </form>
                    </div>
                @endcan
            @endif
        </div>
    </div>

    {{-- ─────────────────────────────── header ─────────────────────────── --}}
    <div class="card mb-5 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ $sale->invoice_no }}</h2>
                    <span class="{{ $sale->status->badgeClass() }}">{{ $sale->status->label() }}</span>
                    <span class="{{ $sale->paymentBadgeClass() }}">{{ $sale->paymentLabel() }}</span>
                    @if ($sale->print_count > 0)
                        <span class="badge-slate">Printed {{ $sale->print_count + 1 }}×</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $sale->status->description() }}</p>
            </div>

            <dl class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-400">Customer</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">
                        @if ($sale->customer)
                            <a href="{{ route('app.customers.show', $sale->customer) }}" class="hover:text-brand-700 dark:hover:text-brand-300">
                                {{ $sale->customer->name }}
                            </a>
                        @else
                            Walk-in
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Sold at</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $sale->sold_at?->format('d M Y, H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Branch</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $sale->branch?->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Served by</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $sale->seller?->name ?? $sale->user_name }}</dd>
                </div>
            </dl>
        </div>

        @if ($sale->status === \App\Enums\SaleStatus::Voided)
            <p class="mt-4 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                Voided {{ $sale->voided_at?->format('d M Y, H:i') }} — {{ $sale->void_reason }}
            </p>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- ────────────────────────────── lines ───────────────────────── --}}
        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">What was sold</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">Item</th>
                            <th class="px-5 py-3 text-right font-medium">Qty</th>
                            <th class="px-5 py-3 text-right font-medium">Price</th>
                            @if ($canSeeProfit)
                                <th class="px-5 py-3 text-right font-medium">Cost</th>
                            @endif
                            <th class="px-5 py-3 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($sale->items as $item)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $item->description }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ $item->product?->sku }}
                                        @if ((float) $item->discount_amount > 0)
                                            · −{{ number_format((float) $item->discount_amount, 2) }} discount
                                        @endif
                                        @if ((float) $item->tax_rate > 0)
                                            · {{ rtrim(rtrim(number_format((float) $item->tax_rate, 2), '0'), '.') }}% tax
                                        @endif
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ rtrim(rtrim(number_format((float) $item->quantity, 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ number_format((float) $item->unit_price, 2) }}
                                </td>
                                @if ($canSeeProfit)
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                                        {{ number_format((float) $item->unit_cost, 2) }}
                                    </td>
                                @endif
                                <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                    {{ number_format($item->net(), 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ────────────────────────────── money ───────────────────────── --}}
        <div class="space-y-5">
            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">The money</h3>

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Subtotal</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $sale->subtotal, 2) }}</dd>
                    </div>
                    @if ((float) $sale->discount_total > 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Discount</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200">−{{ number_format((float) $sale->discount_total, 2) }}</dd>
                        </div>
                    @endif
                    @if ((float) $sale->tax_total > 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Tax</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $sale->tax_total, 2) }}</dd>
                        </div>
                    @endif
                    @if ((float) $sale->rounding != 0)
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Rounding</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $sale->rounding, 2) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                        <dt class="font-semibold text-slate-900 dark:text-white">Total</dt>
                        <dd class="text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format((float) $sale->total, 2) }}</dd>
                    </div>
                </dl>

                <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Paid with</p>
                    <dl class="space-y-1.5 text-sm">
                        @forelse ($sale->payments as $payment)
                            <div class="flex justify-between">
                                <dt class="text-slate-600 dark:text-slate-300">
                                    {{ $payment->label() }}
                                    @if ($payment->reference)
                                        <span class="text-xs text-slate-400">· {{ $payment->reference }}</span>
                                    @endif
                                </dt>
                                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $payment->amount, 2) }}</dd>
                            </div>
                        @empty
                            <p class="text-slate-400">Nothing was handed over — the whole sale went on account.</p>
                        @endforelse

                        @if ((float) $sale->change_given > 0)
                            <div class="flex justify-between text-emerald-600 dark:text-emerald-400">
                                <dt>Change given</dt>
                                <dd class="tabular-nums">{{ number_format((float) $sale->change_given, 2) }}</dd>
                            </div>
                        @endif

                        @if ((float) $sale->due_amount > 0)
                            <div class="flex justify-between border-t border-slate-100 pt-1.5 font-medium text-amber-600 dark:border-slate-800 dark:text-amber-400">
                                <dt>On account</dt>
                                <dd class="tabular-nums">{{ number_format((float) $sale->due_amount, 2) }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Profit is a cost question, and cost is a permission (#52). --}}
            @if ($canSeeProfit)
                <div class="card p-5">
                    <h3 class="font-semibold text-slate-900 dark:text-white">What it made</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Cost of goods</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ number_format((float) $sale->cost_total, 2) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                            <dt class="font-semibold text-slate-900 dark:text-white">Gross profit</dt>
                            <dd class="text-lg font-bold tabular-nums {{ $sale->grossProfit() >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                {{ number_format($sale->grossProfit(), 2) }}
                            </dd>
                        </div>
                        @if ($sale->marginPercent() !== null)
                            <p class="text-xs text-slate-400">
                                {{ number_format($sale->marginPercent(), 1) }}% margin, at the cost that applied when it sold.
                            </p>
                        @endif
                    </dl>
                </div>
            @endif

            @if ($sale->notes)
                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Notes</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $sale->notes }}</p>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
