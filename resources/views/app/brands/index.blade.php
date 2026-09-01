<x-layouts.app title="Brands">

    <x-flash />
    @include('app.catalog-tabs')

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">Brands</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Who makes what you sell. Optional — plenty of shops never need one — but it gives reports another
                way to slice the numbers.
            </p>
        </div>
        <div class="card p-5">
            <x-meter :meter="$meter" />
            <a href="{{ route('app.brands.create') }}" class="btn btn-primary mt-4 w-full">
                <x-icon name="plus" class="h-4 w-4" /> New brand
            </a>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Brand</th>
                        <th class="px-5 py-3 font-medium">Products</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($brands as $brand)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $brand->name }}</p>
                                @if ($brand->description)
                                    <p class="text-xs text-slate-400">{{ $brand->description }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $brand->products_count }}</td>
                            <td class="px-5 py-3">
                                <span class="{{ $brand->is_active ? 'badge-green' : 'badge-slate' }}">
                                    {{ $brand->is_active ? 'Active' : 'Off' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('app.brands.edit', $brand) }}" class="btn btn-ghost !px-2" title="Edit">
                                        <x-icon name="pencil" class="h-4 w-4" />
                                    </a>
                                    @if ($brand->canBeDeleted())
                                        <form method="POST" action="{{ route('app.brands.destroy', $brand) }}"
                                              data-confirm="Delete this brand?">
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
                            <td colspan="4" class="px-5 py-10 text-center text-slate-400">No brands yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-layouts.app>
