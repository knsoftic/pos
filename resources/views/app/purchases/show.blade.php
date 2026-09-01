<x-layouts.app :title="$purchase->reference">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.purchases.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to purchases
        </a>

        <div class="flex flex-wrap items-center gap-2">
            @if ($purchase->status->isEditable())
                @can(\App\Support\PermissionRegistry::PURCHASES_UPDATE)
                    <a href="{{ route('app.purchases.edit', $purchase) }}" class="btn btn-secondary">
                        <x-icon name="pencil" class="h-4 w-4" /> Edit draft
                    </a>
                @endcan

                @can(\App\Support\PermissionRegistry::PURCHASES_CREATE)
                    <form method="POST" action="{{ route('app.purchases.order', $purchase) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="arrow-right" class="h-4 w-4" /> Send to supplier
                        </button>
                    </form>
                @endcan
            @endif

            @if ($purchase->status->hasPosted() && Route::has('app.purchases.returns.create'))
                @can(\App\Support\PermissionRegistry::PURCHASES_RETURN)
                    <a href="{{ route('app.purchases.returns.create', $purchase) }}" class="btn btn-secondary">
                        <x-icon name="arrow-left" class="h-4 w-4" /> Return goods
                    </a>
                @endcan
            @endif

            @if ($purchase->status->canBeCancelled())
                @can(\App\Support\PermissionRegistry::PURCHASES_VOID)
                    <div x-data="{ asking: false }" class="relative">
                        <button type="button" class="btn btn-ghost text-rose-600 dark:text-rose-400" @click="asking = ! asking">
                            <x-icon name="ban" class="h-4 w-4" /> Cancel
                        </button>

                        <form method="POST" action="{{ route('app.purchases.cancel', $purchase) }}"
                              x-show="asking" x-cloak @click.outside="asking = false"
                              class="card absolute right-0 z-20 mt-2 w-80 space-y-2 p-4">
                            @csrf
                            <label for="reason" class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                                Why is this order being called off?
                            </label>
                            <input id="reason" name="reason" type="text" required maxlength="255"
                                   placeholder="Supplier out of stock" class="input !py-2 text-sm" />
                            @if ($purchase->status->hasPosted())
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Anything already received stays received — only what is still outstanding is abandoned.
                                </p>
                            @endif
                            <button type="submit" class="btn btn-primary w-full !py-2 text-sm">Cancel order</button>
                        </form>
                    </div>
                @endcan
            @endif
        </div>
    </div>

    {{-- ------------------------------------------------------- the header --}}
    <div class="card mb-5 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ $purchase->reference }}</h2>
                    <span class="{{ $purchase->status->badgeClass() }}">{{ $purchase->status->label() }}</span>
                    <span class="{{ $purchase->paymentBadgeClass() }}">{{ $purchase->paymentLabel() }}</span>
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $purchase->status->description() }}</p>
            </div>

            <dl class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-400">Supplier</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">
                        <a href="{{ route('app.suppliers.show', $purchase->supplier) }}" class="hover:text-brand-700 dark:hover:text-brand-300">
                            {{ $purchase->supplier?->name }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Deliver to</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $purchase->branch?->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Ordered</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $purchase->order_date?->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400">Expected</dt>
                    <dd class="font-medium text-slate-800 dark:text-slate-200">
                        {{ $purchase->expected_date?->format('d M Y') ?? '—' }}
                    </dd>
                </div>
            </dl>
        </div>

        @if ($purchase->status === \App\Enums\PurchaseStatus::Cancelled)
            <p class="mt-4 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                Cancelled: {{ $purchase->cancellation_reason }}
            </p>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- ---------------------------------------------------- the lines --}}
        <div class="lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Lines</h3>
                    <p class="mt-0.5 text-xs text-slate-400">
                        What was ordered, and what has actually arrived so far.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-400">
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <th class="px-5 py-3 font-medium">Item</th>
                                <th class="px-5 py-3 text-right font-medium">Ordered</th>
                                <th class="px-5 py-3 text-right font-medium">Received</th>
                                @if ($canSeeCost)
                                    <th class="px-5 py-3 text-right font-medium">Unit cost</th>
                                    <th class="px-5 py-3 text-right font-medium">Line total</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($purchase->items as $item)
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $item->description }}</p>
                                        <p class="text-xs text-slate-400">
                                            {{ $item->product?->sku }}
                                            @if ($item->batch_number)
                                                · Lot {{ $item->batch_number }}
                                            @endif
                                            @if ($item->expiry_date)
                                                · exp {{ $item->expiry_date->format('d M Y') }}
                                            @endif
                                            @if ($item->returnedQuantity() > 0)
                                                · <span class="text-amber-600 dark:text-amber-400">
                                                    {{ rtrim(rtrim(number_format($item->returnedQuantity(), 4), '0'), '.') }} returned
                                                </span>
                                            @endif
                                        </p>
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                        {{ rtrim(rtrim(number_format((float) $item->quantity_ordered, 4), '0'), '.') }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums font-medium {{ $item->isFullyReceived() ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                        {{ rtrim(rtrim(number_format((float) $item->quantity_received, 4), '0'), '.') }}
                                    </td>
                                    @if ($canSeeCost)
                                        <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                            {{ number_format((float) $item->unit_cost, 2) }}
                                            @if ((float) $item->discount_amount > 0)
                                                <span class="block text-xs text-slate-400">
                                                    −{{ number_format((float) $item->discount_amount, 2) }} disc
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                            {{ number_format($item->net(), 2) }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>

                        @if ($canSeeCost)
                            <tfoot class="border-t-2 border-slate-200 text-sm dark:border-slate-700">
                                <tr>
                                    <td colspan="3" class="px-5 py-2 text-right text-xs uppercase tracking-wide text-slate-400">Subtotal</td>
                                    <td colspan="2" class="px-5 py-2 text-right tabular-nums text-slate-700 dark:text-slate-200">
                                        {{ number_format((float) $purchase->subtotal, 2) }}
                                    </td>
                                </tr>
                                @if ((float) $purchase->discount_total > 0)
                                    <tr>
                                        <td colspan="3" class="px-5 py-2 text-right text-xs uppercase tracking-wide text-slate-400">Discounts</td>
                                        <td colspan="2" class="px-5 py-2 text-right tabular-nums text-slate-700 dark:text-slate-200">
                                            −{{ number_format((float) $purchase->discount_total, 2) }}
                                        </td>
                                    </tr>
                                @endif
                                @if ((float) $purchase->tax_total > 0)
                                    <tr>
                                        <td colspan="3" class="px-5 py-2 text-right text-xs uppercase tracking-wide text-slate-400">Tax</td>
                                        <td colspan="2" class="px-5 py-2 text-right tabular-nums text-slate-700 dark:text-slate-200">
                                            {{ number_format((float) $purchase->tax_total, 2) }}
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td colspan="3" class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Order total</td>
                                    <td colspan="2" class="px-5 py-3 text-right text-base font-bold tabular-nums text-slate-900 dark:text-white">
                                        {{ number_format((float) $purchase->total, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            {{-- --------------------------------------------------- returns --}}
            @if ($purchase->returns->isNotEmpty())
                <div class="card mt-5 overflow-hidden">
                    <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <h3 class="font-semibold text-slate-900 dark:text-white">Returned to supplier</h3>
                    </div>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($purchase->returns as $return)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $return->reference }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ $return->return_date?->format('d M Y') }} · {{ $return->reason }}
                                    </p>
                                </div>
                                <span class="tabular-nums font-medium text-amber-600 dark:text-amber-400">
                                    −{{ number_format((float) $return->total, 2) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- --------------------------------------------------- the actions --}}
        <div class="space-y-5">
            {{-- Receiving (#35, #119) --}}
            @if ($purchase->status->canReceive())
                @can(\App\Support\PermissionRegistry::PURCHASES_CREATE)
                    <div class="card p-5">
                        <h3 class="font-semibold text-slate-900 dark:text-white">Receive the delivery</h3>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Leave a line blank and all of what is outstanding is taken in. Stock, the supplier's
                            account and any payment all land together.
                        </p>

                        <form method="POST" action="{{ route('app.purchases.receive', $purchase) }}" class="mt-4 space-y-3">
                            @csrf

                            <div class="space-y-2">
                                @foreach ($purchase->items as $item)
                                    @continue($item->isFullyReceived())
                                    <div>
                                        <label for="received-{{ $item->id }}" class="mb-1 block text-xs text-slate-500 dark:text-slate-400">
                                            {{ $item->description }}
                                            <span class="text-slate-400">
                                                ({{ rtrim(rtrim(number_format($item->outstanding(), 4), '0'), '.') }} outstanding)
                                            </span>
                                        </label>
                                        <input id="received-{{ $item->id }}" type="number" step="0.0001" min="0"
                                               max="{{ $item->outstanding() }}"
                                               name="received[{{ $item->id }}]"
                                               placeholder="{{ rtrim(rtrim(number_format($item->outstanding(), 4), '0'), '.') }}"
                                               class="input !py-2 text-sm" />
                                    </div>
                                @endforeach
                            </div>

                            <div>
                                <label for="received_date" class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Delivery date</label>
                                <input id="received_date" name="received_date" type="date" max="{{ now()->toDateString() }}"
                                       value="{{ now()->toDateString() }}" class="input !py-2 text-sm" />
                            </div>

                            <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                <p class="mb-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    Paying now? <span class="font-normal">(optional)</span>
                                </p>
                                <div class="space-y-2">
                                    <input name="pay_now" type="number" step="0.01" min="0" placeholder="0.00"
                                           class="input !py-2 text-sm" />
                                    @if (! empty($paymentMethods))
                                        <select name="payment_method" class="input !py-2 text-sm">
                                            <option value="">Method</option>
                                            @foreach ($paymentMethods as $method)
                                                <option value="{{ $method }}">{{ Str::headline($method) }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-full">
                                <x-icon name="check" class="h-4 w-4" /> Receive
                            </button>
                        </form>
                    </div>
                @endcan
            @endif

            {{-- The bill --}}
            @if ($purchase->status->hasPosted())
                <div class="card p-5">
                    <h3 class="font-semibold text-slate-900 dark:text-white">The bill</h3>

                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex items-baseline justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Received value</dt>
                            <dd class="font-medium tabular-nums text-slate-900 dark:text-white">
                                {{ number_format($purchase->receivedValue(), 2) }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Paid</dt>
                            <dd class="tabular-nums text-emerald-600 dark:text-emerald-400">
                                {{ number_format((float) $purchase->paid_amount, 2) }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                            <dt class="font-semibold text-slate-900 dark:text-white">Still to pay</dt>
                            <dd class="text-lg font-bold tabular-nums {{ $purchase->isSettled() ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                {{ number_format($purchase->balanceDue(), 2) }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-slate-400">
                        You are billed for what arrived, not for what was ordered. The money itself lives on the
                        supplier's account.
                    </p>

                    @unless ($purchase->isSettled())
                        @can(\App\Support\PermissionRegistry::SUPPLIERS_LEDGER)
                            <form method="POST" action="{{ route('app.purchases.payments', $purchase) }}" class="mt-4 space-y-2">
                                @csrf
                                <input name="amount" type="number" step="0.01" min="0.01" required
                                       value="{{ $purchase->balanceDue() }}" class="input !py-2 text-sm" />
                                @if (! empty($paymentMethods))
                                    <select name="payment_method" class="input !py-2 text-sm">
                                        <option value="">Method</option>
                                        @foreach ($paymentMethods as $method)
                                            <option value="{{ $method }}">{{ Str::headline($method) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button type="submit" class="btn btn-primary w-full !py-2 text-sm">
                                    <x-icon name="credit-card" class="h-4 w-4" /> Pay supplier
                                </button>
                            </form>
                        @endcan
                    @endunless
                </div>
            @endif

            @if ($purchase->notes)
                <div class="card p-5">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Notes</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $purchase->notes }}</p>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
