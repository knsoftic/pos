<x-layouts.app :title="$supplier->name">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.suppliers.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to suppliers
        </a>

        <div class="flex flex-wrap items-center gap-2">
            @can(\App\Support\PermissionRegistry::SUPPLIERS_MANAGE)
                <a href="{{ route('app.suppliers.edit', $supplier) }}" class="btn btn-secondary">
                    <x-icon name="pencil" class="h-4 w-4" /> Edit
                </a>

                <div x-data="{ asking: false }" class="relative">
                    @if ($supplier->is_active)
                        <button type="button" class="btn btn-ghost text-rose-600 dark:text-rose-400" @click="asking = ! asking">
                            <x-icon name="ban" class="h-4 w-4" /> Block
                        </button>

                        <form method="POST" action="{{ route('app.suppliers.toggle', $supplier) }}"
                              x-show="asking" x-cloak @click.outside="asking = false"
                              class="card absolute right-0 z-20 mt-2 w-72 space-y-2 p-4">
                            @csrf
                            <label for="blocked_reason" class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                                Why are you blocking them?
                            </label>
                            <input id="blocked_reason" name="blocked_reason" type="text" maxlength="255"
                                   placeholder="Quality dispute" class="input !py-2 text-sm" />
                            <button type="submit" class="btn btn-primary w-full !py-2 text-sm">Block supplier</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('app.suppliers.toggle', $supplier) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost text-emerald-600 dark:text-emerald-400">
                                <x-icon name="check-circle" class="h-4 w-4" /> Unblock
                            </button>
                        </form>
                    @endif
                </div>
            @endcan
        </div>
    </div>

    @unless ($supplier->is_active)
        <div class="card mb-5 border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10">
            <p class="flex items-center gap-2 font-semibold text-rose-800 dark:text-rose-300">
                <x-icon name="ban" class="h-4 w-4" /> This supplier is blocked
            </p>
            <p class="mt-1 text-sm text-rose-700/90 dark:text-rose-300/80">
                {{ $supplier->blocked_reason ?: 'No reason recorded.' }}
                Their record and balance are untouched — you simply cannot buy from them until unblocked.
            </p>
        </div>
    @endunless

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Purchased', 'value' => number_format($summary['purchased'], 2), 'meta' => 'billed to you', 'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10', 'icon' => 'purchases'],
                ['label' => 'Paid', 'value' => number_format($summary['paid'], 2), 'meta' => 'sent to them', 'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10', 'icon' => 'credit-card'],
                ['label' => 'Returned', 'value' => number_format($summary['returned'], 2), 'meta' => 'goods sent back', 'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10', 'icon' => 'arrow-left'],
                [
                    'label' => match ($supplier->balanceDirection()) { 'owing' => 'You owe', 'in_credit' => 'In advance', default => 'Balance' },
                    'value' => number_format($supplier->absoluteBalance(), 2),
                    'meta' => $supplier->payment_terms_days !== null ? $supplier->payment_terms_days.'-day terms' : 'no agreed terms',
                    'tint' => $supplier->balanceDirection() === 'owing' ? 'text-rose-600 bg-rose-50 dark:bg-rose-500/10' : 'text-slate-600 bg-slate-100 dark:bg-slate-800',
                    'icon' => 'suppliers',
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

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5">
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold text-slate-900 dark:text-white">{{ $supplier->name }}</h3>
                        <p class="text-xs text-slate-400">{{ $supplier->code }}</p>
                    </div>
                    <span class="{{ $supplier->is_active ? 'badge-green' : 'badge-red' }}">
                        {{ $supplier->is_active ? 'Active' : 'Blocked' }}
                    </span>
                </div>

                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                    @foreach ([
                        'Contact' => $supplier->contact_person,
                        'Phone' => $supplier->phone,
                        'Email' => $supplier->email,
                        'Address' => $supplier->address,
                        'City' => $supplier->city,
                        'Tax number' => $supplier->tax_number,
                        'Terms' => $supplier->payment_terms_days !== null ? $supplier->payment_terms_days.' days' : null,
                    ] as $label => $value)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="text-right text-slate-800 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($supplier->notes)
                    <p class="mt-4 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                        {{ $supplier->notes }}
                    </p>
                @endif
            </div>

            @can(\App\Support\PermissionRegistry::SUPPLIERS_LEDGER)
                @include('app.partials.ledger-actions', [
                    'paymentAction' => route('app.suppliers.payments', $supplier),
                    'adjustmentAction' => route('app.suppliers.adjustments', $supplier),
                    'paymentMethods' => $paymentMethods,
                    'paymentTitle' => 'Record a payment',
                    'paymentHint' => 'Money paid out to this supplier. Paying more than you owe leaves the balance in your favour — an advance.',
                    'buttonLabel' => 'Record payment',
                    'debitHint' => 'you owe them more',
                    'creditHint' => 'you owe them less',
                ])
            @endcan
        </div>

        <div class="lg:col-span-2">
            @include('app.partials.ledger-statement', [
                'entries' => $entries,
                'totals' => $totals,
                'from' => $from,
                'to' => $to,
                'action' => route('app.suppliers.show', $supplier),
                'debitLabel' => 'Billed',
                'creditLabel' => 'Paid / returned',
                'balanceLabel' => 'You owe',
            ])
        </div>
    </div>

</x-layouts.app>
