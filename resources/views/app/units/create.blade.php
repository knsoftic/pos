<x-layouts.app title="New unit">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.units.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to units
        </a>
    </div>
    @include('app.units.form', ['action' => route('app.units.store'), 'method' => 'POST'])
</x-layouts.app>
