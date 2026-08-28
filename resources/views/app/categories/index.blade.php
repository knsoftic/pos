<x-layouts.app title="Categories">

    <x-flash />
    @include('app.catalog-tabs')

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-semibold text-slate-900 dark:text-white">How your products are filed</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Two levels: a category, and subcategories under it. A category that still holds products or
                subcategories can be switched off, but not deleted — the products would lose their filing.
            </p>
        </div>

        <div class="card p-5">
            <x-meter :meter="$meter" />
            <a href="{{ route('app.categories.create') }}" class="btn btn-primary mt-4 w-full">
                <x-icon name="plus" class="h-4 w-4" /> New category
            </a>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Category</th>
                        <th class="px-5 py-3 font-medium">Products</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($categories as $category)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $category->name }}</p>
                                @if ($category->description)
                                    <p class="text-xs text-slate-400">{{ $category->description }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $category->products_count }}</td>
                            <td class="px-5 py-3">
                                <span class="{{ $category->is_active ? 'badge-green' : 'badge-slate' }}">
                                    {{ $category->is_active ? 'Active' : 'Off' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                @include('app.categories.row-actions', ['category' => $category])
                            </td>
                        </tr>

                        @foreach ($category->children as $child)
                            <tr class="bg-slate-50/40 hover:bg-slate-50 dark:bg-slate-800/20 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3 pl-10">
                                    <p class="flex items-center gap-2 text-slate-700 dark:text-slate-300">
                                        <span class="text-slate-300 dark:text-slate-600">└</span>
                                        {{ $child->name }}
                                    </p>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $child->products_count }}</td>
                                <td class="px-5 py-3">
                                    <span class="{{ $child->is_active ? 'badge-green' : 'badge-slate' }}">
                                        {{ $child->is_active ? 'Active' : 'Off' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    @include('app.categories.row-actions', ['category' => $child])
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-slate-400">
                                No categories yet — products work fine without one, but filing helps once the
                                catalogue grows.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-layouts.app>
