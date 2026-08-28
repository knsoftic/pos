{{--
    Shared role editor (create + edit).

    Only permissions the plan currently supports are listed — the controller
    passes `groups` from PermissionService::grantableGrouped(), so a box that
    layer 1 would veto is never on screen (#125). Anything already granted but
    now dormant is shown read-only at the bottom and preserved on save.
--}}
@props(['role', 'groups', 'granted', 'dormantCodes', 'registry', 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Role details</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                <input id="name" name="name" type="text" required maxlength="60"
                       value="{{ old('name', $role->name) }}"
                       placeholder="Shift Supervisor" class="input" />
            </div>
            <div>
                <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Description <span class="text-slate-400">(optional)</span>
                </label>
                <input id="description" name="description" type="text" maxlength="255"
                       value="{{ old('description', $role->description) }}"
                       placeholder="What this role is for" class="input" />
            </div>
        </div>

        @if ($role->exists && $role->is_system)
            <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                This is a starter role. You can rename it and change its permissions — it just cannot be deleted.
            </p>
        @endif
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">Permissions</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Amber ones are sensitive — money, cost prices, exports and anything that changes what others may do.
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <button type="button" class="btn btn-ghost !py-1.5"
                        onclick="document.querySelectorAll('[data-perm]').forEach(c => c.checked = true)">Select all</button>
                <button type="button" class="btn btn-ghost !py-1.5"
                        onclick="document.querySelectorAll('[data-perm]').forEach(c => c.checked = false)">Clear</button>
            </div>
        </div>

        @foreach ($groups as $group => $permissions)
            <div class="border-b border-slate-100 last:border-0 dark:border-slate-800">
                <p class="bg-slate-50/60 px-5 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
                    {{ \App\Support\PermissionRegistry::groupLabel($group) }}
                </p>

                <div class="grid grid-cols-1 gap-px bg-slate-100 md:grid-cols-2 dark:bg-slate-800">
                    @foreach ($permissions as $code => $meta)
                        @php $checked = in_array($code, old('permissions', $granted), true); @endphp
                        <label class="flex cursor-pointer items-start gap-3 bg-white px-5 py-3 hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800/60">
                            <input type="checkbox" name="permissions[]" value="{{ $code }}" data-perm
                                   @checked($checked)
                                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $meta['name'] }}</span>
                                    @if ($meta['sensitive'])
                                        <span class="badge-amber">Sensitive</span>
                                    @endif
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $meta['description'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @if ($dormantCodes)
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Paused permissions</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                This role still holds these, but your current plan does not include them, so they do nothing right now.
                They are kept as they are — upgrade and they work again.
            </p>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($dormantCodes as $code)
                    <span class="badge-slate">{{ $registry[$code]['name'] ?? $code }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.roles.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $role->exists ? 'Save changes' : 'Create role' }}
        </button>
    </div>
</form>
