<x-layouts.app :title="$customer->name">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.customers.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to customers
        </a>

        <div class="flex flex-wrap items-center gap-2">
            @can(\App\Support\PermissionRegistry::CUSTOMERS_MANAGE)
                <a href="{{ route('app.customers.edit', $customer) }}" class="btn btn-secondary">
                    <x-icon name="pencil" class="h-4 w-4" /> Edit
                </a>

                {{-- Blocking asks for a reason inline rather than through a
                     browser prompt: the reason ends up on the record, so it
                     deserves a real field. --}}
                <div x-data="{ asking: false }" class="relative">
                    @if ($customer->is_active)
                        <button type="button" class="btn btn-ghost text-rose-600 dark:text-rose-400" @click="asking = ! asking">
                            <x-icon name="ban" class="h-4 w-4" /> Block
                        </button>

                        <form method="POST" action="{{ route('app.customers.toggle', $customer) }}"
                              x-show="asking" x-cloak @click.outside="asking = false"
                              class="card absolute right-0 z-20 mt-2 w-72 space-y-2 p-4">
                            @csrf
                            <label for="blocked_reason" class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                                Why are you blocking them?
                            </label>
                            <input id="blocked_reason" name="blocked_reason" type="text" maxlength="255"
                                   placeholder="Repeated late payment" class="input !py-2 text-sm" />
                            <button type="submit" class="btn btn-primary w-full !py-2 text-sm">Block customer</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('app.customers.toggle', $customer) }}">
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

    @unless ($customer->is_active)
        <div class="card mb-5 border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10">
            <p class="flex items-center gap-2 font-semibold text-rose-800 dark:text-rose-300">
                <x-icon name="ban" class="h-4 w-4" /> This customer is blocked
            </p>
            <p class="mt-1 text-sm text-rose-700/90 dark:text-rose-300/80">
                {{ $customer->blocked_reason ?: 'No reason recorded.' }}
                Their record and balance are untouched — they simply cannot buy on account until unblocked.
            </p>
        </div>
    @endunless

    {{-- --------------------------------------------------- profile figures --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Purchased', 'value' => number_format($summary['purchased'], 2), 'meta' => 'charged to the account', 'tint' => 'text-brand-600 bg-brand-50 dark:bg-brand-500/10', 'icon' => 'sales'],
                ['label' => 'Paid', 'value' => number_format($summary['paid'], 2), 'meta' => 'received from them', 'tint' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10', 'icon' => 'credit-card'],
                ['label' => 'Returned', 'value' => number_format($summary['returned'], 2), 'meta' => 'goods back', 'tint' => 'text-amber-600 bg-amber-50 dark:bg-amber-500/10', 'icon' => 'arrow-left'],
                [
                    'label' => match ($customer->balanceDirection()) { 'owing' => 'Owes you', 'in_credit' => 'In credit', default => 'Balance' },
                    'value' => number_format($customer->absoluteBalance(), 2),
                    'meta' => $customer->isOverLimit() ? 'over their credit limit' : 'as of today',
                    'tint' => $customer->balanceDirection() === 'owing' ? 'text-rose-600 bg-rose-50 dark:bg-rose-500/10' : 'text-slate-600 bg-slate-100 dark:bg-slate-800',
                    'icon' => 'customers',
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
            {{-- ------------------------------------------------- details --}}
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold text-slate-900 dark:text-white">{{ $customer->name }}</h3>
                        <p class="text-xs text-slate-400">{{ $customer->code }}</p>
                    </div>
                    <span class="{{ $customer->is_active ? 'badge-green' : 'badge-red' }}">
                        {{ $customer->is_active ? 'Active' : 'Blocked' }}
                    </span>
                </div>

                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                    @foreach ([
                        'Phone' => $customer->phone,
                        'Email' => $customer->email,
                        'Address' => $customer->address,
                        'City' => $customer->city,
                        'Tax number' => $customer->tax_number,
                    ] as $label => $value)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="text-right text-slate-800 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach

                    <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2 dark:border-slate-800">
                        <dt class="text-slate-500 dark:text-slate-400">Credit limit</dt>
                        <dd class="text-right">
                            @if ($customer->hasUnlimitedCredit())
                                <span class="badge-brand">No limit</span>
                            @elseif ((float) $customer->credit_limit === 0.0)
                                <span class="text-slate-500 dark:text-slate-400">Cash only</span>
                            @else
                                <span class="font-medium tabular-nums text-slate-800 dark:text-slate-200">
                                    {{ number_format((float) $customer->credit_limit, 2) }}
                                </span>
                                <span class="block text-xs text-slate-400">
                                    {{ number_format($customer->availableCredit() ?? 0, 2) }} still available
                                </span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($customer->notes)
                    <p class="mt-4 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                        {{ $customer->notes }}
                    </p>
                @endif
            </div>

            {{-- --------------------------------------------- money actions --}}
            @can(\App\Support\PermissionRegistry::CUSTOMERS_LEDGER)
                @if (Route::has('app.customers.payments'))
                    @include('app.partials.ledger-actions', [
                        'paymentAction' => route('app.customers.payments', $customer),
                        'adjustmentAction' => route('app.customers.adjustments', $customer),
                        'paymentMethods' => $paymentMethods,
                        'paymentTitle' => 'Record a payment',
                        'paymentHint' => 'Money received from this customer. Paying more than they owe simply leaves the account in credit.',
                        'buttonLabel' => 'Record payment',
                        'debitHint' => 'they owe you more',
                        'creditHint' => 'they owe you less',
                    ])
                @endif
            @endcan
        </div>

        <div class="lg:col-span-2">
            @include('app.partials.ledger-statement', [
                'entries' => $entries,
                'totals' => $totals,
                'from' => $from,
                'to' => $to,
                'action' => route('app.customers.show', $customer),
                'debitLabel' => 'Charged',
                'creditLabel' => 'Paid / returned',
                'balanceLabel' => 'Owes',
            ])
        </div>
    </div>

</x-layouts.app>
