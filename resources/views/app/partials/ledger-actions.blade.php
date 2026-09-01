{{--
    Recording money and corrections on a party account (#41, #42).

    Shared by both sides; each passes its own routes and its own word for the
    money moving ("Received" vs "Paid"), because a supplier screen saying
    "record payment received" would be quietly wrong.
--}}
@props([
    'paymentAction',
    'adjustmentAction',
    'paymentMethods' => [],
    'paymentTitle' => 'Record a payment',
    'paymentHint' => 'Money taken against what is owed.',
    'buttonLabel' => 'Record payment',
    'debitHint' => 'they owe more',
    'creditHint' => 'they owe less',
])

<div class="space-y-5">
    {{-- ------------------------------------------------------- payment --}}
    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">{{ $paymentTitle }}</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $paymentHint }}</p>

        <form method="POST" action="{{ $paymentAction }}" class="mt-4 space-y-3">
            @csrf

            <div>
                <label for="amount" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Amount</label>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                       value="{{ old('amount') }}" placeholder="0.00" class="input" />
                @error('amount')
                    <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            @if (! empty($paymentMethods))
                <div>
                    <label for="payment_method" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Method</label>
                    <select id="payment_method" name="payment_method" class="input">
                        <option value="">Not stated</option>
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method }}" @selected(old('payment_method') === $method)>
                                {{ Str::headline($method) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="entry_date" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Date</label>
                    <input id="entry_date" name="entry_date" type="date" max="{{ now()->toDateString() }}"
                           value="{{ old('entry_date', now()->toDateString()) }}" class="input" />
                </div>
                <div>
                    <label for="reference_no" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Ref no.</label>
                    <input id="reference_no" name="reference_no" type="text" maxlength="60"
                           value="{{ old('reference_no') }}" placeholder="Optional" class="input" />
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-full">
                <x-icon name="check" class="h-4 w-4" /> {{ $buttonLabel }}
            </button>
        </form>
    </div>

    {{-- ---------------------------------------------------- adjustment --}}
    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Adjust the balance</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            A correction, not a payment. Positive means <strong>{{ $debitHint }}</strong>; negative means
            <strong>{{ $creditHint }}</strong>. The reason goes on the statement and cannot be edited later.
        </p>

        <form method="POST" action="{{ $adjustmentAction }}" class="mt-4 space-y-3">
            @csrf

            <div>
                <label for="adjust_amount" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Change by</label>
                <input id="adjust_amount" name="amount" type="number" step="0.01" required
                       placeholder="-250.00" class="input" />
            </div>

            <div>
                <label for="reason" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Reason</label>
                <input id="reason" name="reason" type="text" required maxlength="255"
                       placeholder="Rounding written off" class="input" />
                @error('reason')
                    <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="adjust_date" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Date</label>
                <input id="adjust_date" name="entry_date" type="date" max="{{ now()->toDateString() }}"
                       value="{{ now()->toDateString() }}" class="input" />
            </div>

            <button type="submit" class="btn btn-secondary w-full">
                <x-icon name="pencil" class="h-4 w-4" /> Post adjustment
            </button>
        </form>
    </div>
</div>
