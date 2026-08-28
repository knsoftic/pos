{{--
    Shared unit form (create + edit). #26, #158

    The conversion fields only appear when the plan includes multi-unit: the
    service refuses a derived unit without it, so showing the field would be
    inviting a refusal.
--}}
@props(['unit', 'baseUnits', 'multiUnit', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5" x-data="{ base: '{{ old('base_unit_id', $unit->base_unit_id) }}' }">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                <input id="name" name="name" type="text" required maxlength="60"
                       value="{{ old('name', $unit->name) }}" placeholder="Kilogram" class="input" />
            </div>

            <div>
                <label for="short_name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Short name</label>
                <input id="short_name" name="short_name" type="text" required maxlength="12"
                       value="{{ old('short_name', $unit->short_name) }}" placeholder="kg" class="input" />
                <p class="mt-1 text-xs text-slate-400">Shown next to every quantity, so keep it short.</p>
            </div>

            @if ($multiUnit)
                <div>
                    <label for="base_unit_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Converts to <span class="text-slate-400">(optional)</span>
                    </label>
                    <select id="base_unit_id" name="base_unit_id" class="input" x-model="base">
                        <option value="">Nothing — this is a base unit</option>
                        @foreach ($baseUnits as $base)
                            <option value="{{ $base->id }}" @selected((int) old('base_unit_id', $unit->base_unit_id) === $base->id)>
                                {{ $base->name }} ({{ $base->short_name }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div x-show="base !== ''" x-cloak>
                    <label for="conversion_factor" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        How many base units is one worth?
                    </label>
                    <input id="conversion_factor" name="conversion_factor" type="number" step="0.000001" min="0.000001"
                           value="{{ old('conversion_factor', $unit->exists ? rtrim(rtrim((string) $unit->conversion_factor, '0'), '.') : '') }}"
                           placeholder="12" class="input" />
                    <p class="mt-1 text-xs text-slate-400">A Dozen is 12 Pieces. A Gram is 0.001 Kilogram.</p>
                </div>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            <label class="flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="allows_decimals" value="1" @checked(old('allows_decimals', $unit->allows_decimals))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                <span>
                    <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Allow fractions</span>
                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                        On for weighed goods (1.25 kg). Off for things you count — selling 2.5 phones should be
                        impossible.
                    </span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $unit->exists ? $unit->is_active : true))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Active</span>
            </label>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.units.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $unit->exists ? 'Save changes' : 'Create unit' }}
        </button>
    </div>
</form>
