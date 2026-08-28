<x-layouts.app :title="'Edit ' . $role->name">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('app.roles.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to roles
        </a>
    </div>

    @include('app.roles.form', [
        'action' => route('app.roles.update', $role),
        'method' => 'PUT',
    ])

</x-layouts.app>
