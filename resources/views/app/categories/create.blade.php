<x-layouts.app title="New category">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.categories.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to categories
        </a>
    </div>
    @include('app.categories.form', ['action' => route('app.categories.store'), 'method' => 'POST'])
</x-layouts.app>
