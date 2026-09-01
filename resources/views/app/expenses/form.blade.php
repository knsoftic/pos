@php $editing = $expense->exists; @endphp

<x-layouts.app :title="$editing ? 'Edit '.$expense->reference : 'Record an expense'">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('app.expenses.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to expenses
        </a>
    </div>

    <form method="POST" enctype="multipart/form-data"
          action="{{ $editing ? route('app.expenses.update', $expense) : route('app.expenses.store') }}"
          class="space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">
                {{ $editing ? $expense->reference : 'What was the money spent on?' }}
            </h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Rent, wages, electricity, repairs — anything that is not stock. Goods bought for resale belong in
                <a href="{{ route('app.purchases.index') }}" class="text-brand-600 hover:underline dark:text-brand-400">purchases</a>:
                their cost reaches profit when they sell, not when they arrive.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="expense_category_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Category</label>
                    <select id="expense_category_id" name="expense_category_id" required class="input">
                        <option value="">Choose one…</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}"
                                @selected((int) old('expense_category_id', $expense->expense_category_id) === $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('expense_category_id') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">
                        Not there? <a href="{{ route('app.expense-categories.index') }}" class="text-brand-600 hover:underline dark:text-brand-400">Add a category</a>.
                    </p>
                </div>

                <div>
                    <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Amount</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                           value="{{ old('amount', $expense->exists ? number_format((float) $expense->amount, 2, '.', '') : '') }}"
                           class="input" />
                    @error('amount') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="expense_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Date</label>
                    <input id="expense_date" name="expense_date" type="date" required max="{{ now()->toDateString() }}"
                           value="{{ old('expense_date', $expense->expense_date?->toDateString() ?? now()->toDateString()) }}"
                           class="input" />
                    @error('expense_date') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="payment_method" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Paid with</label>
                    <select id="payment_method" name="payment_method" class="input">
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method }}" @selected(old('payment_method', $expense->payment_method ?? 'cash') === $method)>
                                {{ Str::headline($method) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        Cash comes out of the open till, so the cash-up stays honest.
                    </p>
                </div>

                <div>
                    <label for="payee" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Paid to <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="payee" name="payee" type="text" maxlength="255"
                           value="{{ old('payee', $expense->payee) }}" placeholder="K-Electric" class="input" />
                </div>

                <div>
                    <label for="bill_no" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Their bill number <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="bill_no" name="bill_no" type="text" maxlength="60"
                           value="{{ old('bill_no', $expense->bill_no) }}" class="input" />
                </div>

                @if ($branches->count() > 1)
                    <div>
                        <label for="branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Branch</label>
                        <select id="branch_id" name="branch_id" class="input">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}"
                                    @selected((int) old('branch_id', $expense->branch_id ?? auth()->user()->branch_id) === $branch->id)>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">
                            Every expense belongs to a branch, so branch figures add up to the whole.
                        </p>
                    </div>
                @endif

                <div class="{{ $branches->count() > 1 ? '' : 'md:col-span-2' }}">
                    <label for="note" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Note <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="note" name="note" type="text" maxlength="255"
                           value="{{ old('note', $expense->note) }}" class="input" />
                </div>
            </div>
        </div>

        {{-- ───────────────────────────── receipt ──────────────────────────── --}}
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">The receipt</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                A photo or a PDF, up to {{ number_format(config('uploads.receipts.max_kb') / 1024, 1) }} MB. This is
                the difference between a note in a ledger and something an auditor will accept.
            </p>

            @if ($expense->hasAttachment())
                <div class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/50">
                    <x-icon name="note" class="h-5 w-5 text-slate-400" />
                    <a href="{{ Storage::disk(config('uploads.receipts.disk'))->url($expense->attachment_path) }}"
                       target="_blank" rel="noopener"
                       class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                        {{ $expense->attachment_name }}
                    </a>
                    <span class="text-xs text-slate-400">{{ $expense->attachmentSizeForHumans() }}</span>

                    <label class="ml-auto flex cursor-pointer items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <input type="checkbox" name="remove_attachment" value="1"
                               class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500 dark:border-slate-600 dark:bg-slate-800" />
                        Remove it
                    </label>
                </div>
            @endif

            <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf"
                   class="mt-3 block w-full text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200 dark:text-slate-400 dark:file:bg-slate-800 dark:file:text-slate-200 dark:hover:file:bg-slate-700" />
            @error('attachment') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror

            @if ($expense->hasAttachment())
                <p class="mt-2 text-xs text-slate-400">Choosing a new file replaces the one above.</p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('app.expenses.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-4 w-4" /> {{ $editing ? 'Save changes' : 'Record expense' }}
            </button>
        </div>
    </form>

</x-layouts.app>
