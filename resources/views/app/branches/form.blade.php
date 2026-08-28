{{-- Shared branch form (create + edit). #47 --}}
@props(['branch', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Branch details</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                <input id="name" name="name" type="text" required maxlength="120"
                       value="{{ old('name', $branch->name) }}" placeholder="High Street" class="input" />
            </div>

            <div>
                <label for="code" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Code <span class="text-slate-400">(optional)</span>
                </label>
                <input id="code" name="code" type="text" maxlength="20"
                       value="{{ old('code', $branch->code) }}" placeholder="HIGHST" class="input uppercase" />
                <p class="mt-1 text-xs text-slate-400">
                    Short tag used on invoice numbers and reports. Left blank, one is generated from the name.
                </p>
            </div>

            <div>
                <label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Phone</label>
                <input id="phone" name="phone" type="text" maxlength="40"
                       value="{{ old('phone', $branch->phone) }}" class="input" />
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
                <input id="email" name="email" type="email" maxlength="255"
                       value="{{ old('email', $branch->email) }}" class="input" />
            </div>

            <div>
                <label for="address" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Address</label>
                <input id="address" name="address" type="text" maxlength="255"
                       value="{{ old('address', $branch->address) }}" class="input" />
            </div>

            <div>
                <label for="city" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">City</label>
                <input id="city" name="city" type="text" maxlength="80"
                       value="{{ old('city', $branch->city) }}" class="input" />
            </div>
        </div>

        @if (! $branch->is_main)
            <label class="mt-4 flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $branch->exists ? $branch->is_active : true))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                <span>
                    <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Open for business</span>
                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                        A closed branch keeps all its history — it just stops taking new work.
                    </span>
                </span>
            </label>
        @else
            <p class="mt-4 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                This is your main branch, so it cannot be closed. Make another branch the main one first if you need to.
            </p>
        @endif
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.branches.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $branch->exists ? 'Save changes' : 'Create branch' }}
        </button>
    </div>
</form>
