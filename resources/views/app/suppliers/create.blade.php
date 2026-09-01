<x-layouts.app title="New supplier">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.suppliers.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to suppliers
        </a>
    </div>
    @include('app.suppliers.form', ['action' => route('app.suppliers.store'), 'method' => 'POST'])
</x-layouts.app>
