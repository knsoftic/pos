<x-layouts.app :title="'Edit ' . $supplier->name">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.suppliers.show', $supplier) }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $supplier->name }}
        </a>
    </div>
    @include('app.suppliers.form', ['action' => route('app.suppliers.update', $supplier), 'method' => 'PUT'])
</x-layouts.app>
