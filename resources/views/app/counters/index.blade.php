<x-layouts.app title="POS counters">

    <x-flash />

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">Tills</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Each till belongs to a branch. Cashiers are assigned to one, and from the POS every sale and cash
                session records the counter it happened on.
            </p>

            @unless ($multiCounter)
                <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    Your plan covers a single counter. Adding a second one needs a plan with multiple POS counters.
                </p>
            @endunless
        </div>

        <div class="card p-5">
            <x-meter :meter="$meter" />

            @if ($multiCounter)
                <a href="{{ route('app.counters.create') }}" class="btn btn-primary mt-4 w-full">
                    <x-icon name="plus" class="h-4 w-4" /> New counter
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
                        <th class="px-5 py-3 font-medium">Counter</th>
                        <th class="px-5 py-3 font-medium">Branch</th>
                        <th class="px-5 py-3 font-medium">Assigned staff</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($counters as $counter)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10">
                                        <x-icon name="counter" class="h-4 w-4" />
                                    </span>
                                    <div>
                                        <p class="font-medium text-slate-900 dark:text-white">{{ $counter->name }}</p>
                                        <p class="text-xs text-slate-400">{{ $counter->code }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $counter->branch?->name ?? '—' }}</td>
                            <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $counter->users_count }}</td>
                            <td class="px-5 py-3">
                                <span class="{{ $counter->is_active ? 'badge-green' : 'badge-slate' }}">
                                    {{ $counter->is_active ? 'Active' : 'Disabled' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.counters.edit', $counter) }}" class="btn btn-ghost !px-2" title="Edit">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </a>

                                    <form method="POST" action="{{ route('app.counters.toggle', $counter) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost !px-2" title="{{ $counter->is_active ? 'Disable' : 'Enable' }}">
                                            <x-icon name="{{ $counter->is_active ? 'ban' : 'check-circle' }}" class="h-4 w-4" />
                                        </button>
                                    </form>

                                    @if ($counter->canBeDeleted())
                                        <form method="POST" action="{{ route('app.counters.destroy', $counter) }}"
                                              data-confirm="Delete {{ $counter->name }}?">
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
                            <td colspan="5" class="px-5 py-10 text-center text-slate-400">No counters yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-layouts.app>
