<x-layouts.app title="Branches">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">Your locations</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Stock, sales and cash sessions all belong to a branch. Staff see the branch they are assigned to; you see
                every one of them.
            </p>

            @unless ($multiBranch)
                <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    Your plan covers a single branch. You can rename and update this one — adding another needs a plan
                    with multiple branches.
                </p>
            @endunless
        </div>

        <div class="card p-5">
            <x-meter :meter="$meter" />

            @if ($multiBranch)
                <a href="{{ route('app.branches.create') }}" class="btn btn-primary mt-4 w-full">
                    <x-icon name="plus" class="h-4 w-4" /> New branch
                </a>
            @else
                <a href="{{ route('app.billing.plans') }}" class="btn btn-secondary mt-4 w-full">
                    <x-icon name="arrow-right" class="h-4 w-4" /> Compare plans
                </a>
            @endif
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Branch</th>
                        <th class="px-5 py-3 font-medium">Contact</th>
                        <th class="px-5 py-3 font-medium">Counters</th>
                        <th class="px-5 py-3 font-medium">Staff</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($branches as $branch)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10">
                                        <x-icon name="branches" class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-2 font-medium text-slate-900 dark:text-white">
                                            {{ $branch->name }}
                                            @if ($branch->is_main)
                                                <span class="badge-brand">Main</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-slate-400">{{ $branch->code }}{{ $branch->city ? ' · '.$branch->city : '' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                <p>{{ $branch->phone ?? '—' }}</p>
                                <p class="text-xs text-slate-400">{{ $branch->email ?? '' }}</p>
                            </td>
                            <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $branch->counters_count }}</td>
                            <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $branch->users_count }}</td>
                            <td class="px-5 py-3">
                                <span class="{{ $branch->is_active ? 'badge-green' : 'badge-slate' }}">
                                    {{ $branch->is_active ? 'Open' : 'Closed' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.branches.edit', $branch) }}" class="btn btn-ghost !px-2" title="Edit">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </a>

                                    @unless ($branch->is_main)
                                        <form method="POST" action="{{ route('app.branches.main', $branch) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost !px-2" title="Make this the main branch">
                                                <x-icon name="pin" class="h-4 w-4" />
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('app.branches.toggle', $branch) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost !px-2" title="{{ $branch->is_active ? 'Close branch' : 'Reopen branch' }}">
                                                <x-icon name="{{ $branch->is_active ? 'ban' : 'check-circle' }}" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endunless

                                    @if ($branch->canBeDeleted())
                                        <form method="POST" action="{{ route('app.branches.destroy', $branch) }}"
                                              data-confirm="Delete {{ $branch->name }}?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400" title="Delete">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">No branches yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-layouts.app>
