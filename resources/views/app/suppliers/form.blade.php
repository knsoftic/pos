{{-- Shared supplier form (create + edit). #38 --}}
@props(['supplier', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Who they are</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Company name</label>
                <input id="name" name="name" type="text" required maxlength="180"
                       value="{{ old('name', $supplier->name) }}" class="input" />
                @error('name') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="code" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Code <span class="text-slate-400">(optional)</span>
                </label>
                <input id="code" name="code" type="text" maxlength="30"
                       value="{{ old('code', $supplier->code) }}" placeholder="Generated if left blank"
                       class="input uppercase" />
                @error('code') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="contact_person" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Contact person
                </label>
                <input id="contact_person" name="contact_person" type="text" maxlength="180"
                       value="{{ old('contact_person', $supplier->contact_person) }}"
                       placeholder="The person you actually ring" class="input" />
            </div>

            <div>
                <label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Phone</label>
                <input id="phone" name="phone" type="text" maxlength="40"
                       value="{{ old('phone', $supplier->phone) }}" class="input" />
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
                <input id="email" name="email" type="email" maxlength="255"
                       value="{{ old('email', $supplier->email) }}" class="input" />
                @error('email') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="payment_terms_days" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Payment terms <span class="text-slate-400">(days)</span>
                </label>
                <input id="payment_terms_days" name="payment_terms_days" type="number" min="0" max="365"
                       value="{{ old('payment_terms_days', $supplier->payment_terms_days) }}"
                       placeholder="No agreed terms" class="input" />
                <p class="mt-1 text-xs text-slate-400">How long they give you to pay. Blank is not the same as due immediately.</p>
            </div>

            <div class="md:col-span-2">
                <label for="address" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Address</label>
                <input id="address" name="address" type="text" maxlength="255"
                       value="{{ old('address', $supplier->address) }}" class="input" />
            </div>

            <div>
                <label for="city" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">City</label>
                <input id="city" name="city" type="text" maxlength="80"
                       value="{{ old('city', $supplier->city) }}" class="input" />
            </div>

            <div>
                <label for="tax_number" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Tax number <span class="text-slate-400">(optional)</span>
                </label>
                <input id="tax_number" name="tax_number" type="text" maxlength="60"
                       value="{{ old('tax_number', $supplier->tax_number) }}" class="input" />
            </div>
        </div>
    </div>

    @unless ($supplier->exists)
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Opening balance <span class="text-sm font-normal text-slate-400">(optional)</span></h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                What you already owed them when you started keeping books here. Posted as the first line of their
                statement. Negative means they hold money of yours.
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
            <textarea id="notes" name="notes" rows="2" maxlength="2000" class="input">{{ old('notes', $supplier->notes) }}</textarea>
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $supplier->exists ? $supplier->is_active : true))
                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
            <span>
                <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Active</span>
                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                    A blocked supplier keeps their record and balance — you simply cannot buy from them.
                </span>
            </span>
        </label>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ $supplier->exists ? route('app.suppliers.show', $supplier) : route('app.suppliers.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $supplier->exists ? 'Save changes' : 'Create supplier' }}
        </button>
    </div>
</form>
