<x-layouts.app :title="'Edit ' . $purchase->reference">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.purchases.show', $purchase) }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $purchase->reference }}
        </a>
    </div>
    @include('app.purchases.form', ['action' => route('app.purchases.update', $purchase), 'method' => 'PUT'])
</x-layouts.app>
