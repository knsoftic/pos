<x-layouts.app title="Products">

    <x-flash />
    @include('app.catalog-tabs')

    <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <form method="GET" action="{{ route('app.products.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div class="sm:col-span-2 lg:col-span-3">
                    <label for="search" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Search</label>
                    <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                           placeholder="Name, SKU or barcode — variants too" class="input" />
                </div>

                <div>
                    <label for="category" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Category</label>
                    <select id="category" name="category" class="input">
                        <option value="">Any</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="brand" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Brand</label>
                    <select id="brand" name="brand" class="input">
                        <option value="">Any</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}" @selected($filters['brand'] === (string) $brand->id)>{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="status" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Status</label>
                    <select id="status" name="status" class="input">
                        <option value="">Any</option>
                        <option value="active" @selected($filters['status'] === 'active')>Active</option>
                        <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                    </select>
                </div>

                <div class="sm:col-span-2 lg:col-span-3 flex items-center gap-2">
                    <button type="submit" class="btn btn-secondary">
                        <x-icon name="filter" class="h-4 w-4" /> Apply
                    </button>
                    @if (array_filter($filters))
                        <a href="{{ route('app.products.index') }}" class="btn btn-ghost">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="card p-5">
            <x-meter :meter="$meter" />
            @can(\App\Support\PermissionRegistry::PRODUCTS_CREATE)
                <a href="{{ route('app.products.create') }}" class="btn btn-primary mt-4 w-full">
                    <x-icon name="plus" class="h-4 w-4" /> New product
                </a>
            @endcan
            @unless ($canSeeCost)
                <p class="mt-3 text-xs text-slate-400">Cost prices are hidden for your role.</p>
            @endunless
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-400">
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <th class="px-5 py-3 font-medium">Product</th>
                        <th class="px-5 py-3 font-medium">Filed under</th>
                        <th class="px-5 py-3 font-medium">Type</th>
                        @if ($canSeeCost)
                            <th class="px-5 py-3 text-right font-medium">Cost</th>
                        @endif
                        <th class="px-5 py-3 text-right font-medium">Price</th>
                        @if ($canSeeCost)
                            <th class="px-5 py-3 text-right font-medium">Margin</th>
                        @endif
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($products as $product)
                        @php $range = $product->priceRange(); @endphp
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800">
                                        <x-icon name="products" class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ $product->name }}</p>
                                        <p class="truncate text-xs text-slate-400">
                                            {{ $product->sku }}{{ $product->barcode ? ' · '.$product->barcode : '' }}
                                            @if ($product->hasVariants())
                                                · {{ $product->variants->count() }} {{ Str::plural('variant', $product->variants->count()) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                                <p>{{ $product->category?->name ?? '—' }}</p>
                                <p class="text-xs text-slate-400">{{ $product->brand?->name ?? '' }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $product->type->badgeClass() }}">{{ $product->type->label() }}</span>
                            </td>
                            @if ($canSeeCost)
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ number_format((float) $product->cost_price, 2) }}
                                </td>
                            @endif
                            <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                @if ($product->hasVariants() && $range['min'] !== $range['max'])
                                    {{ number_format($range['min'], 2) }} – {{ number_format($range['max'], 2) }}
                                @else
                                    {{ number_format($range['min'], 2) }}
                                @endif
                            </td>
                            @if ($canSeeCost)
                                <td class="px-5 py-3 text-right tabular-nums">
                                    @php $margin = $product->marginPercent(); @endphp
                                    @if ($margin === null)
                                        <span class="text-slate-400">—</span>
                                    @else
                                        <span @class([
                                            'font-medium',
                                            'text-emerald-600 dark:text-emerald-400' => $margin >= 20,
                                            'text-amber-600 dark:text-amber-400' => $margin < 20 && $margin >= 0,
                                            'text-rose-600 dark:text-rose-400' => $margin < 0,
                                        ])>{{ number_format($margin, 1) }}%</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-5 py-3">
                                <span class="{{ $product->is_active ? 'badge-green' : 'badge-slate' }}">
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @can(\App\Support\PermissionRegistry::PRODUCTS_UPDATE)
                                        <a href="{{ route('app.products.edit', $product) }}" class="btn btn-ghost !px-2" title="Edit">
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </a>
                                        <form method="POST" action="{{ route('app.products.toggle', $product) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost !px-2" title="{{ $product->is_active ? 'Deactivate' : 'Activate' }}">
                                                <x-icon name="{{ $product->is_active ? 'ban' : 'check-circle' }}" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endcan
                                    @can(\App\Support\PermissionRegistry::PRODUCTS_DELETE)
                                        <form method="POST" action="{{ route('app.products.destroy', $product) }}"
                                              onsubmit="return confirm('Delete this product? Anything with history is kept and archived instead.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400" title="Delete">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canSeeCost ? 8 : 6 }}" class="px-5 py-10 text-center text-slate-400">
                                @if (array_filter($filters))
                                    Nothing matches those filters.
                                @else
                                    No products yet — add your first one to get started.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                {{ $products->links() }}
            </div>
        @endif
    </div>

</x-layouts.app>
