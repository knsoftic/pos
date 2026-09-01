<x-layouts.app :title="'Return against ' . $sale->invoice_no">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('app.sales.show', $sale) }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $sale->invoice_no }}
        </a>
    </div>

    @php
        // Everything the money panel needs to reason about, computed once.
        $returnable = $sale->items->filter(fn ($item) => $item->returnableQuantity() > 0);
        $hasAccount = $sale->customer_id !== null;
    @endphp

    <form method="POST" action="{{ route('app.returns.store', $sale) }}" class="space-y-5"
          x-data="returnForm({
              lines: @js($sale->items->mapWithKeys(fn ($i) => [$i->id => [
                  'returnable' => $i->returnableQuantity(),
                  'unit' => $i->effectiveUnitPrice(),
              ]])),
              hasAccount: {{ $hasAccount ? 'true' : 'false' }},
          })">
        @csrf

        {{-- ────────────────────────────── why ─────────────────────────── --}}
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">
                Goods coming back from {{ $sale->customerName() }}
            </h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Posted immediately. Anything you mark as fit to sell goes back on the shelf; the rest is written off.
                The sale itself is never rewritten — this is its own record.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Reason</label>
                    <input id="reason" name="reason" type="text" required maxlength="255"
                           value="{{ old('reason') }}" placeholder="Wrong size" class="input" />
                    @error('reason') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="return_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Date</label>
                    <input id="return_date" name="return_date" type="date" max="{{ now()->toDateString() }}"
                           value="{{ old('return_date', now()->toDateString()) }}" class="input" />
                    @error('return_date') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="notes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Notes <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="notes" name="notes" type="text" maxlength="2000"
                           value="{{ old('notes') }}" class="input" />
                </div>
            </div>
        </div>

        {{-- ────────────────────────────── what ────────────────────────── --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">What is coming back</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Leave a line at zero to keep it. Untick <strong>Fit to sell</strong> for anything damaged — the
                    customer still gets their money, but the goods do not go back on the shelf.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">Item</th>
                            <th class="px-5 py-3 text-right font-medium">Sold</th>
                            <th class="px-5 py-3 text-right font-medium">Already back</th>
                            <th class="px-5 py-3 text-right font-medium">Returning</th>
                            <th class="px-5 py-3 font-medium">Fit to sell</th>
                            <th class="px-5 py-3 text-right font-medium">Refund</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($sale->items as $item)
                            @php $canReturn = $item->returnableQuantity(); @endphp
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $item->description }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ $item->product?->sku }} ·
                                        {{ number_format($item->effectiveUnitPrice(), 2) }} each, all in
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ rtrim(rtrim(number_format((float) $item->quantity, 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                                    {{ rtrim(rtrim(number_format($item->returnedQuantity(), 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($canReturn > 0)
                                        <input type="number" step="0.0001" min="0" max="{{ $canReturn }}"
                                               name="lines[{{ $item->id }}][quantity]"
                                               x-model.number="lines[{{ $item->id }}].quantity"
                                               placeholder="0" class="input !w-24 !py-1.5 text-right text-sm" />
                                    @else
                                        <span class="text-xs text-slate-400">All returned</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @if ($canReturn > 0)
                                        <label class="flex cursor-pointer items-center gap-2">
                                            <input type="hidden" name="lines[{{ $item->id }}][restock]" value="0" />
                                            <input type="checkbox" value="1" checked
                                                   name="lines[{{ $item->id }}][restock]"
                                                   x-model="lines[{{ $item->id }}].restock"
                                                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                                            <span class="text-xs text-slate-500 dark:text-slate-400">Back on the shelf</span>
                                        </label>

                                        <input type="text" maxlength="255"
                                               name="lines[{{ $item->id }}][condition_note]"
                                               x-show="! lines[{{ $item->id }}].restock" x-cloak
                                               placeholder="What is wrong with it?"
                                               class="input mt-1.5 !py-1 text-xs" />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white"
                                    x-text="money(lineTotal({{ $item->id }}))">0.00</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-slate-400">This sale has no lines.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ────────────────────────── the money ───────────────────────── --}}
        <div class="card p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h3 class="font-semibold text-slate-900 dark:text-white">Giving the money back</h3>
                <p class="text-lg font-bold tabular-nums text-slate-900 dark:text-white" x-text="money(total())"></p>
            </div>

            @if ($hasAccount)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $sale->customer->name }} has an account, so this is credited to it by default — which is
                    usually what both sides want when they still owe money. Hand cash back instead by putting an
                    amount in the refund box.
                </p>
            @else
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    A walk-in has no account to credit, so the whole amount is handed back.
                </p>
            @endif

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label for="refund_amount" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Handed back
                    </label>
                    <input id="refund_amount" name="refund_amount" type="number" step="0.01" min="0"
                           x-model.number="refund"
                           :placeholder="hasAccount ? '0.00' : money(total())" class="input" />
                    @error('refund_amount') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="refund_method" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Handed back as
                    </label>
                    <select id="refund_method" name="refund_method" class="input">
                        @foreach ($paymentMethods as $method)
                            @continue($method === config('pos.credit_method'))
                            <option value="{{ $method }}" @selected(old('refund_method', 'cash') === $method)>
                                {{ Str::headline($method) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($hasAccount)
                    <div>
                        <label for="credit_amount" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                            Credited to the account
                        </label>
                        <input id="credit_amount" name="credit_amount" type="number" step="0.01" min="0"
                               x-model.number="credit" :placeholder="money(total())" class="input" />
                    </div>
                @endif
            </div>

            <p x-show="! settles()" x-cloak
               class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                The refund and the credit come to <span class="font-semibold" x-text="money(settled())"></span>,
                but the return is worth <span class="font-semibold" x-text="money(total())"></span>. Leave both blank
                to let the shop decide.
            </p>
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('app.sales.show', $sale) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-4 w-4" /> Record return
            </button>
        </div>
    </form>

    @push('scripts')
    <script>
        /*
         | Only the arithmetic the person filling the form needs to see. The
         | server recomputes every figure from the sale's own lines, so nothing
         | here is authoritative — it exists so the total does not arrive as a
         | surprise after submitting.
         */
        function returnForm(config) {
            return {
                hasAccount: config.hasAccount,
                lines: Object.fromEntries(Object.entries(config.lines).map(([id, l]) => [
                    id, { quantity: 0, restock: true, unit: l.unit },
                ])),
                refund: null,
                credit: null,

                lineTotal(id) {
                    const line = this.lines[id];
                    return line ? (line.quantity || 0) * line.unit : 0;
                },
                total() {
                    return Object.keys(this.lines).reduce((sum, id) => sum + this.lineTotal(id), 0);
                },
                settled() { return (this.refund || 0) + (this.credit || 0); },
                settles() {
                    // Both blank means "you decide", which is always fine.
                    if (! this.refund && ! this.credit) return true;
                    // One filled means the rest goes the other way — also fine.
                    if (! this.refund || ! this.credit) return true;
                    return Math.abs(this.settled() - this.total()) < 0.005;
                },
                money(v) {
                    return (v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },
            };
        }
    </script>
    @endpush

</x-layouts.app>
