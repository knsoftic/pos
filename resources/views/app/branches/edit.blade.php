<x-layouts.app :title="'Edit ' . $branch->name">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('app.branches.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to branches
        </a>
    </div>

    @include('app.branches.form', [
        'action' => route('app.branches.update', $branch),
        'method' => 'PUT',
    ])

</x-layouts.app>
