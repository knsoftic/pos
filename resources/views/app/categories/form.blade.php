{{-- Shared category form (create + edit). #26 --}}
@props(['category', 'parents', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                <input id="name" name="name" type="text" required maxlength="120"
                       value="{{ old('name', $category->name) }}" placeholder="Drinks" class="input" />
            </div>

            <div>
                <label for="parent_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Sits under <span class="text-slate-400">(optional)</span>
                </label>
                <select id="parent_id" name="parent_id" class="input">
                    <option value="">Top level</option>
                    @foreach ($parents as $parent)
                        <option value="{{ $parent->id }}" @selected((int) old('parent_id', $category->parent_id) === $parent->id)>
                            {{ $parent->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-2">
                <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Description <span class="text-slate-400">(optional)</span>
                </label>
                <input id="description" name="description" type="text" maxlength="255"
                       value="{{ old('description', $category->description) }}" class="input" />
            </div>

            <div>
                <label for="sort_order" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Sort order</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="9999"
                       value="{{ old('sort_order', $category->sort_order ?? 0) }}" class="input" />
            </div>
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->exists ? $category->is_active : true))
                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
            <span>
                <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Active</span>
                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                    Switching it off hides it when adding products; existing products keep their filing.
                </span>
            </span>
        </label>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.categories.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $category->exists ? 'Save changes' : 'Create category' }}
        </button>
    </div>
</form>
