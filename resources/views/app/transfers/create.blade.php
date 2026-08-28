<x-layouts.app title="New transfer">
    <x-flash />
    <div class="mb-5">
        <a href="{{ route('app.transfers.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to transfers
        </a>
    </div>
    @include('app.transfers.form', ['action' => route('app.transfers.store'), 'method' => 'POST'])
</x-layouts.app>
