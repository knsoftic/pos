<x-layouts.app title="Roles & permissions">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <p class="max-w-2xl text-sm text-slate-500 dark:text-slate-400">
            A role is a set of permissions. Everyone except you needs one — you are the owner, so you always have
            everything. Starter roles can be edited but not deleted.
        </p>
        <a href="{{ route('app.roles.create') }}" class="btn btn-primary">
            <x-icon name="plus" class="h-4 w-4" /> New role
        </a>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($roles as $role)
            @php
                $codes = $role->permissionCodes();
                $sensitive = $role->sensitivePermissionCodes();
                $roleDormant = $dormant[$role->id] ?? [];
            @endphp

            <div class="card flex flex-col p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate font-bold text-slate-900 dark:text-white">{{ $role->name }}</h3>
                            @if ($role->is_system)
                                <span class="badge-slate">Starter</span>
                            @endif
                        </div>
                        @if ($role->description)
                            <p class="mt-1 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">{{ $role->description }}</p>
                        @endif
                    </div>
                    <span class="badge-brand shrink-0">{{ $role->users_count }} {{ Str::plural('person', $role->users_count) }}</span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-2 border-t border-slate-100 pt-4 text-center dark:border-slate-800">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-slate-400">Permissions</dt>
                        <dd class="mt-0.5 text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ count($codes) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-slate-400">Sensitive</dt>
                        <dd class="mt-0.5 text-lg font-bold tabular-nums {{ $sensitive ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">
                            {{ count($sensitive) }}
                        </dd>
                    </div>
                </dl>

                @if ($sensitive)
                    <div class="mt-3 flex flex-wrap gap-1">
                        @foreach (array_slice($sensitive, 0, 4) as $code)
                            <span class="badge-amber">{{ $registry[$code]['name'] ?? $code }}</span>
                        @endforeach
                        @if (count($sensitive) > 4)
                            <span class="badge-slate">+{{ count($sensitive) - 4 }} more</span>
                        @endif
                    </div>
                @endif

                {{-- Granted, but the plan no longer covers it. Kept, not dropped. --}}
                @if ($roleDormant)
                    <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                        {{ count($roleDormant) }} {{ Str::plural('permission', count($roleDormant)) }} paused — not in your
                        current plan. They come back if you upgrade.
                    </p>
                @endif

                <div class="mt-auto flex items-center gap-2 pt-4">
                    <a href="{{ route('app.roles.edit', $role) }}" class="btn btn-secondary flex-1">
                        <x-icon name="pencil" class="h-4 w-4" /> Edit
                    </a>

                    @if ($role->canBeDeleted())
                        <form method="POST" action="{{ route('app.roles.destroy', $role) }}"
                              onsubmit="return confirm('Delete the role &quot;{{ $role->name }}&quot;?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400" title="Delete role">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </form>
                    @else
                        <span class="btn btn-ghost !px-2 cursor-not-allowed text-slate-300 dark:text-slate-600"
                              title="{{ $role->is_system ? 'Starter roles cannot be deleted' : 'Someone is using this role' }}">
                            <x-icon name="trash" class="h-4 w-4" />
                        </span>
                    @endif
                </div>
            </div>
        @empty
            <div class="card p-10 text-center md:col-span-2 xl:col-span-3">
                <p class="text-slate-500 dark:text-slate-400">No roles yet.</p>
            </div>
        @endforelse
    </div>

</x-layouts.app>
