<x-layouts.app title="Units">

    <x-flash />
    @include('app.catalog-tabs')

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">How you measure what you sell</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Stock is always kept in the base unit. A converted unit just says how many base units it is worth —
                a Dozen is 12 Pieces — so selling one Dozen removes 12 from stock.
            </p>

            @unless ($multiUnit)
                <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    Your plan covers single units. Every unit you add is a base unit; conversions need a plan with
                    multiple units.
                </p>
            @endunless
        </div>
        <div class="card p-5 flex flex-col justify-center">
            <a href="{{ route('app.units.create') }}" class="btn btn-primary w-full">
                <x-icon name="plus" class="h-4 w-4" /> New unit
            </a>
            <p class="mt-3 text-center text-xs text-slate-400">Units are not metered — add as many as you need.</p>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Unit</th>
                        <th class="px-5 py-3 font-medium">Converts to</th>
                        <th class="px-5 py-3 font-medium">Decimals</th>
                        <th class="px-5 py-3 font-medium">Products</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($units as $unit)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $unit->name }}</p>
                                <p class="text-xs text-slate-400">{{ $unit->short_name }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                @if ($unit->isBaseUnit())
                                    <span class="badge-slate">Base unit</span>
                                @else
                                    1 {{ $unit->short_name }} =
                                    <span class="font-medium tabular-nums">{{ rtrim(rtrim(number_format($unit->factor(), 6), '0'), '.') }}</span>
                                    {{ $unit->baseUnit?->short_name }}
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                {{ $unit->allows_decimals ? 'Allowed' : 'Whole numbers' }}
                            </td>
                            <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $unit->products_count }}</td>
                            <td class="px-5 py-3">
                                <span class="{{ $unit->is_active ? 'badge-green' : 'badge-slate' }}">
                                    {{ $unit->is_active ? 'Active' : 'Off' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.units.edit', $unit) }}" class="btn btn-ghost !px-2" title="Edit">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </a>
                                    @if ($unit->canBeDeleted())
                                        <form method="POST" action="{{ route('app.units.destroy', $unit) }}"
                                              data-confirm="Delete this unit?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400" title="Delete">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @else
                                        <span class="btn btn-ghost !px-2 cursor-not-allowed text-slate-300 dark:text-slate-600"
                                              title="In use — switch it off instead">
                                            <x-icon name="trash" class="h-4 w-4" />
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">No units yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-layouts.app>
