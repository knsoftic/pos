{{--
    Shared customer form (create + edit). #39, #40

    The credit block is the part worth reading: "unlimited" is a deliberate
    checkbox, not an empty field, because an empty field is what someone types
    when they mean "none".
--}}
@props(['customer', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5"
      x-data="{ unlimited: {{ old('unlimited_credit', $customer->exists && $customer->hasUnlimitedCredit()) ? 'true' : 'false' }} }">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Who they are</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                <input id="name" name="name" type="text" required maxlength="180"
                       value="{{ old('name', $customer->name) }}" class="input" />
                @error('name') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="code" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Code <span class="text-slate-400">(optional)</span>
                </label>
                <input id="code" name="code" type="text" maxlength="30"
                       value="{{ old('code', $customer->code) }}" placeholder="Generated if left blank"
                       class="input uppercase" />
                @error('code') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Phone</label>
                <input id="phone" name="phone" type="text" maxlength="40"
                       value="{{ old('phone', $customer->phone) }}" class="input" />
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
                <input id="email" name="email" type="email" maxlength="255"
                       value="{{ old('email', $customer->email) }}" class="input" />
                @error('email') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <label for="address" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Address</label>
                <input id="address" name="address" type="text" maxlength="255"
                       value="{{ old('address', $customer->address) }}" class="input" />
            </div>

            <div>
                <label for="city" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">City</label>
                <input id="city" name="city" type="text" maxlength="80"
                       value="{{ old('city', $customer->city) }}" class="input" />
            </div>

            <div>
                <label for="tax_number" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Tax number <span class="text-slate-400">(optional)</span>
                </label>
                <input id="tax_number" name="tax_number" type="text" maxlength="60"
                       value="{{ old('tax_number', $customer->tax_number) }}" class="input" />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------- credit #40 --}}
    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Credit</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            How much this customer may owe at once. Leave the limit at zero for a cash-only account — that is the
            default, because letting someone buy on account should be a decision somebody made.
        </p>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="credit_limit" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Credit limit
                </label>
                <input id="credit_limit" name="credit_limit" type="number" step="0.01" min="0"
                       value="{{ old('credit_limit', $customer->exists && ! $customer->hasUnlimitedCredit() ? (float) $customer->credit_limit : 0) }}"
                       class="input" x-bind:disabled="unlimited" />
                @error('credit_limit') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-end">
                <label class="flex cursor-pointer items-start gap-3 pb-2">
                    <input type="checkbox" name="unlimited_credit" value="1" x-model="unlimited"
                           @checked(old('unlimited_credit', $customer->exists && $customer->hasUnlimitedCredit()))
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                    <span>
                        <span class="text-sm font-medium text-slate-800 dark:text-slate-200">No limit</span>
                        <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                            A trusted account customer. Their balance is still tracked — it just is not capped.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </div>

    {{-- --------------------------------------- opening balance (new only) --}}
    @unless ($customer->exists)
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Opening balance <span class="text-sm font-normal text-slate-400">(optional)</span></h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                What they already owed when you started keeping books here. It is posted as the first line of their
                statement, so day one is part of the same history as day one thousand. Negative means you owe them.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="opening_balance" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Amount</label>
                    <input id="opening_balance" name="opening_balance" type="number" step="0.01"
                           value="{{ old('opening_balance') }}" placeholder="0.00" class="input" />
                </div>
                <div>
                    <label for="opening_balance_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">As at</label>
                    <input id="opening_balance_date" name="opening_balance_date" type="date"
                           max="{{ now()->toDateString() }}"
                           value="{{ old('opening_balance_date', now()->toDateString()) }}" class="input" />
                    @error('opening_balance_date') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
    @endunless

    <div class="card p-5">
        <div>
            <label for="notes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                Notes <span class="text-slate-400">(optional)</span>
            </label>
            <textarea id="notes" name="notes" rows="2" maxlength="2000" class="input">{{ old('notes', $customer->notes) }}</textarea>
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $customer->exists ? $customer->is_active : true))
                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
            <span>
                <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Active</span>
                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                    A blocked customer keeps their record and their balance — they simply cannot transact (#105).
                </span>
            </span>
        </label>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ $customer->exists ? route('app.customers.show', $customer) : route('app.customers.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $customer->exists ? 'Save changes' : 'Create customer' }}
        </button>
    </div>
</form>
