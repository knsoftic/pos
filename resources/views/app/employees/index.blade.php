<x-layouts.app title="Employees">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">Your team</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Everyone here signs in with their own account. What they can do comes from their role; what they can see
                comes from their branch.
            </p>
        </div>

        <div class="card p-5">
            <x-meter :meter="$meter" />

            @can(\App\Support\PermissionRegistry::EMPLOYEES_MANAGE)
                <a href="{{ route('app.employees.create') }}" class="btn btn-primary mt-4 w-full">
                    <x-icon name="plus" class="h-4 w-4" /> Add employee
                </a>
            @endcan
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Person</th>
                        <th class="px-5 py-3 font-medium">Role</th>
                        <th class="px-5 py-3 font-medium">Branch / counter</th>
                        <th class="px-5 py-3 font-medium">Max discount</th>
                        <th class="px-5 py-3 font-medium">Last login</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($employees as $employee)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-sm font-semibold uppercase text-brand-600 dark:bg-brand-500/10">
                                        {{ Str::substr($employee->name, 0, 1) }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-2 font-medium text-slate-900 dark:text-white">
                                            {{ $employee->name }}
                                            @unless ($employee->is_active)
                                                <span class="badge-slate">Inactive</span>
                                            @endunless
                                        </p>
                                        <p class="truncate text-xs text-slate-400">{{ $employee->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $employee->isOwner() ? 'badge-brand' : ($employee->role ? 'badge-slate' : 'badge-amber') }}">
                                    {{ $employee->roleName() }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                <p>{{ $employee->branch?->name ?? ($employee->isOwner() ? 'All branches' : '—') }}</p>
                                <p class="text-xs text-slate-400">{{ $employee->posCounter?->name ?? '' }}</p>
                            </td>
                            <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                @if ($employee->discountCap() === null)
                                    <span class="text-slate-400">No cap</span>
                                @else
                                    {{ rtrim(rtrim(number_format((float) $employee->max_discount_percent, 2), '0'), '.') }}%
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                {{ $employee->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @can(\App\Support\PermissionRegistry::EMPLOYEES_MANAGE)
                                        <a href="{{ route('app.employees.edit', $employee) }}" class="btn btn-ghost !px-2" title="Edit">
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </a>

                                        @unless ($employee->isOwner() || $employee->id === auth()->id())
                                            <form method="POST" action="{{ route('app.employees.reset-password', $employee) }}"
                                                  onsubmit="return confirm('Generate a new password for {{ $employee->name }}?');">
                                                @csrf
                                                <button type="submit" class="btn btn-ghost !px-2" title="Reset password">
                                                    <x-icon name="key" class="h-4 w-4" />
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('app.employees.toggle', $employee) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-ghost !px-2" title="{{ $employee->is_active ? 'Deactivate' : 'Reactivate' }}">
                                                    <x-icon name="{{ $employee->is_active ? 'ban' : 'check-circle' }}" class="h-4 w-4" />
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('app.employees.destroy', $employee) }}"
                                                  onsubmit="return confirm('Remove {{ $employee->name }}? Their past work stays on record.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400" title="Remove">
                                                    <x-icon name="trash" class="h-4 w-4" />
                                                </button>
                                            </form>
                                        @endunless
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</x-layouts.app>
