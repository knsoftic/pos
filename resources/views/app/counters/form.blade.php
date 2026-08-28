{{-- Shared POS counter form (create + edit). #49 --}}
@props(['counter', 'branches', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Counter details</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Branch</label>
                <select id="branch_id" name="branch_id" required class="input">
                    <option value="">Choose a branch…</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('branch_id', $counter->branch_id) === $branch->id)>
                            {{ $branch->name }} ({{ $branch->code }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                <input id="name" name="name" type="text" required maxlength="120"
                       value="{{ old('name', $counter->name) }}" placeholder="Counter 2" class="input" />
            </div>

            <div>
                <label for="code" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Code <span class="text-slate-400">(optional)</span>
                </label>
                <input id="code" name="code" type="text" maxlength="20"
                       value="{{ old('code', $counter->code) }}" placeholder="POS2" class="input uppercase" />
            </div>
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-3">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $counter->exists ? $counter->is_active : true))
                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
            <span>
                <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Available for use</span>
                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                    A disabled till cannot be opened, but everything it has already sold stays on the books.
                </span>
            </span>
        </label>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.counters.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $counter->exists ? 'Save changes' : 'Create counter' }}
        </button>
    </div>
</form>
