<x-layouts.app :title="'Edit ' . $product->name">
    <x-flash />
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.products.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to products
        </a>
        <span class="text-xs text-slate-400">
            SKU {{ $product->sku }}{{ $product->barcode ? ' · barcode '.$product->barcode : '' }}
        </span>
    </div>
    @include('app.products.form', ['action' => route('app.products.update', $product), 'method' => 'PUT'])
</x-layouts.app>
