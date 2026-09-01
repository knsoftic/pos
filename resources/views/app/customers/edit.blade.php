<x-layouts.app :title="'Edit ' . $customer->name">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.customers.show', $customer) }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $customer->name }}
        </a>
    </div>
    @include('app.customers.form', ['action' => route('app.customers.update', $customer), 'method' => 'PUT'])
</x-layouts.app>
