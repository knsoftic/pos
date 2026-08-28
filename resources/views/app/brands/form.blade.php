{{-- Shared brand form (create + edit). #26 --}}
@props(['brand', 'action', 'method' => 'POST'])

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
                       value="{{ old('name', $brand->name) }}" placeholder="Acme" class="input" />
            </div>
            <div>
                <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Description <span class="text-slate-400">(optional)</span>
                </label>
                <input id="description" name="description" type="text" maxlength="255"
                       value="{{ old('description', $brand->description) }}" class="input" />
            </div>
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $brand->exists ? $brand->is_active : true))
                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Active</span>
        </label>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.brands.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $brand->exists ? 'Save changes' : 'Create brand' }}
        </button>
    </div>
</form>
