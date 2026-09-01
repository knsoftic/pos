@php $editing = $income->exists; @endphp

<x-layouts.app :title="$editing ? 'Edit '.$income->reference : 'Record other income'">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('app.income.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to other income
        </a>
    </div>

    <form method="POST" enctype="multipart/form-data"
          action="{{ $editing ? route('app.income.update', $income) : route('app.income.store') }}"
          class="space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">
                {{ $editing ? $income->reference : 'Where did the money come from?' }}
            </h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Anything that came in without stock going out. It is added after gross profit, so it never flatters
                your margin.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="source" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Source</label>
                    <input id="source" name="source" type="text" required maxlength="255"
                           value="{{ old('source', $income->source) }}" placeholder="Scrap cartons sold" class="input" />
                    @error('source') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Amount</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                           value="{{ old('amount', $income->exists ? number_format((float) $income->amount, 2, '.', '') : '') }}"
                           class="input" />
                    @error('amount') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="income_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Date</label>
                    <input id="income_date" name="income_date" type="date" required max="{{ now()->toDateString() }}"
                           value="{{ old('income_date', $income->income_date?->toDateString() ?? now()->toDateString()) }}"
                           class="input" />
                    @error('income_date') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="payment_method" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Received as</label>
                    <select id="payment_method" name="payment_method" class="input">
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method }}" @selected(old('payment_method', $income->payment_method ?? 'cash') === $method)>
                                {{ Str::headline($method) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Cash goes into the open till.</p>
                </div>

                @if ($branches->count() > 1)
                    <div>
                        <label for="branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Branch</label>
                        <select id="branch_id" name="branch_id" class="input">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}"
                                    @selected((int) old('branch_id', $income->branch_id ?? auth()->user()->branch_id) === $branch->id)>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="{{ $branches->count() > 1 ? '' : 'md:col-span-2' }}">
                    <label for="note" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Note <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="note" name="note" type="text" maxlength="255"
                           value="{{ old('note', $income->note) }}" class="input" />
                </div>
            </div>
        </div>

        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Proof</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                A photo or a PDF, up to {{ number_format(config('uploads.receipts.max_kb') / 1024, 1) }} MB.
            </p>

            @if ($income->hasAttachment())
                <div class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/50">
                    <x-icon name="note" class="h-5 w-5 text-slate-400" />
                    <a href="{{ Storage::disk(config('uploads.receipts.disk'))->url($income->attachment_path) }}"
                       target="_blank" rel="noopener"
                       class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                        {{ $income->attachment_name }}
                    </a>
                    <span class="text-xs text-slate-400">{{ $income->attachmentSizeForHumans() }}</span>

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
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('app.income.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-4 w-4" /> {{ $editing ? 'Save changes' : 'Record income' }}
            </button>
        </div>
    </form>

</x-layouts.app>
