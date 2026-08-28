<x-layouts.app title="New product">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.products.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to products
        </a>
    </div>
    @include('app.products.form', ['action' => route('app.products.store'), 'method' => 'POST'])
</x-layouts.app>
