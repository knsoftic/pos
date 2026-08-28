{{--
    Shared employee form (create + edit). #50, #138, #141

    The owner's row is deliberately read-only here: an owner already reaches
    everything, and writing a role onto them is how a business locks itself out
    of itself. The server enforces the same rule — this just says so out loud.
--}}
@props(['employee', 'roles', 'branches', 'counters', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Person</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Full name</label>
                <input id="name" name="name" type="text" required maxlength="120"
                       value="{{ old('name', $employee->name) }}" class="input" />
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Email (their login)</label>
                <input id="email" name="email" type="email" required maxlength="255"
                       value="{{ old('email', $employee->email) }}" class="input" />
            </div>

            <div>
                <label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Phone <span class="text-slate-400">(optional)</span>
                </label>
                <input id="phone" name="phone" type="text" maxlength="40"
                       value="{{ old('phone', $employee->phone) }}" class="input" />
            </div>

            @unless ($employee->exists && $employee->isOwner())
                <div class="flex items-end">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $employee->exists ? $employee->is_active : true))
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                        <span>
                            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Can sign in</span>
                            <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                                Switching this off ends their access immediately, mid-session.
                            </span>
                        </span>
                    </label>
                </div>
            @endunless
        </div>
    </div>

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">
            {{ $employee->exists ? 'Change password' : 'Password' }}
        </h3>
        @if ($employee->exists)
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Leave both boxes empty to keep the current password.</p>
        @endif

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       @required(! $employee->exists) class="input" />
                <x-password-hint class="mt-1" />
            </div>
            <div>
                <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                       @required(! $employee->exists) class="input" />
            </div>
        </div>
    </div>

    @if ($employee->exists && $employee->isOwner())
        <div class="card p-5">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                This is the owner account. It always has every permission and reaches every branch, so there is nothing
                to assign here.
            </p>
        </div>
    @else
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">What they can do and see</h3>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="role_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Role</label>
                    <select id="role_id" name="role_id" class="input">
                        <option value="">No role — cannot do anything yet</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((int) old('role_id', $employee->role_id) === $role->id)>
                                {{ $role->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Permissions come from the role — edit those under Roles.</p>
                </div>

                <div>
                    <label for="branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Branch</label>
                    <select id="branch_id" name="branch_id" class="input">
                        <option value="">No branch — sees no branch data</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id', $employee->branch_id) === $branch->id)>
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">They see the sales, stock and tills of this branch only.</p>
                </div>

                <div>
                    <label for="pos_counter_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        POS counter <span class="text-slate-400">(optional)</span>
                    </label>
                    <select id="pos_counter_id" name="pos_counter_id" class="input">
                        <option value="">Any counter in their branch</option>
                        @foreach ($counters as $counter)
                            <option value="{{ $counter->id }}" @selected((int) old('pos_counter_id', $employee->pos_counter_id) === $counter->id)>
                                {{ $counter->name }} — {{ $counter->branch?->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="max_discount_percent" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Max discount %
                    </label>
                    <input id="max_discount_percent" name="max_discount_percent" type="number" step="0.01" min="0" max="100"
                           value="{{ old('max_discount_percent', $employee->max_discount_percent) }}"
                           placeholder="No cap" class="input" />
                    <p class="mt-1 text-xs text-slate-400">
                        Blank means no cap. <strong>0</strong> means they cannot discount at all.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.employees.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $employee->exists ? 'Save changes' : 'Add employee' }}
        </button>
    </div>
</form>
