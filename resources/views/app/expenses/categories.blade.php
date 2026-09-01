<x-layouts.app title="Expense categories">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('app.expenses.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to expenses
        </a>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- ──────────────────────────── add one ───────────────────────────── --}}
        <div class="card h-fit p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Add a category</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                These are yours. A pharmacy files a dispensary licence; a restaurant files gas cylinders. Nobody
                else's list would fit.
            </p>

            <form method="POST" action="{{ route('app.expense-categories.store') }}" class="mt-4 space-y-3">
                @csrf

                <div>
                    <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                    <input id="name" name="name" type="text" required maxlength="120"
                           value="{{ old('name') }}" placeholder="Packaging" class="input" />
                    @error('name') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Description <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="description" name="description" type="text" maxlength="255"
                           value="{{ old('description') }}" class="input" />
                </div>

                <div>
                    <label for="sort_order" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Sort order
                    </label>
                    <input id="sort_order" name="sort_order" type="number" min="0" max="9999"
                           value="{{ old('sort_order', 0) }}" class="input" />
                </div>

                <input type="hidden" name="is_active" value="1" />

                <button type="submit" class="btn btn-primary w-full">
                    <x-icon name="plus" class="h-4 w-4" /> Add category
                </button>
            </form>
        </div>

        {{-- ───────────────────────────── the list ─────────────────────────── --}}
        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">Your categories</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    A category with expenses filed under it can be switched off, but not deleted — last quarter's
                    figures still need the heading.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">Category</th>
                            <th class="px-5 py-3 text-right font-medium">Entries</th>
                            <th class="px-5 py-3 text-right font-medium">Total</th>
                            <th class="px-5 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($categories as $category)
                            <tr x-data="{ editing: false }" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3">
                                    <div x-show="! editing">
                                        <p class="font-medium text-slate-900 dark:text-white">
                                            {{ $category->name }}
                                            @unless ($category->is_active)
                                                <span class="badge-slate ml-1">Off</span>
                                            @endunless
                                        </p>
                                        @if ($category->description)
                                            <p class="text-xs text-slate-400">{{ $category->description }}</p>
                                        @endif
                                    </div>

                                    <form x-show="editing" x-cloak method="POST"
                                          action="{{ route('app.expense-categories.update', $category) }}"
                                          class="flex flex-wrap items-center gap-2">
                                        @csrf @method('PUT')
                                        <input name="name" type="text" required maxlength="120"
                                               value="{{ $category->name }}" class="input !w-40 !py-1.5 text-sm" />
                                        <input name="description" type="text" maxlength="255"
                                               value="{{ $category->description }}" placeholder="Description"
                                               class="input !w-44 !py-1.5 text-sm" />
                                        <input name="sort_order" type="number" min="0" max="9999"
                                               value="{{ $category->sort_order }}" class="input !w-20 !py-1.5 text-sm" />
                                        <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                            <input type="checkbox" name="is_active" value="1" @checked($category->is_active)
                                                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                                            On
                                        </label>
                                        <button type="submit" class="btn btn-primary !px-3 !py-1.5 text-xs">Save</button>
                                        <button type="button" class="btn btn-ghost !px-3 !py-1.5 text-xs" @click="editing = false">Cancel</button>
                                    </form>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ number_format($category->expenses_count) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ number_format((float) $category->expenses_sum_amount, 2) }}
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" @click="editing = ! editing"
                                                class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                                title="Edit">
                                            <x-icon name="pencil" class="h-4 w-4" />
                                        </button>

                                        @if ($category->canBeDeleted())
                                            <form method="POST" action="{{ route('app.expense-categories.destroy', $category) }}"
                                                  data-confirm="Delete &quot;{{ $category->name }}&quot;?">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                        class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10 dark:hover:text-rose-400"
                                                        title="Delete">
                                                    <x-icon name="trash" class="h-4 w-4" />
                                                </button>
                                            </form>
                                        @else
                                            <span class="rounded-lg p-2 text-slate-300 dark:text-slate-700"
                                                  title="In use — switch it off instead">
                                                <x-icon name="lock" class="h-4 w-4" />
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-slate-400">
                                    No categories yet. Add the first one on the left.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</x-layouts.app>
