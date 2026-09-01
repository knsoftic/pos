<x-layouts.app title="Batches & expiry">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.inventory.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to inventory
        </a>

        <form method="GET" action="{{ route('app.inventory.expiry') }}">
            <select name="branch" class="input !py-1.5 text-xs" onchange="this.form.submit()">
                <option value="">All branches</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($selectedBranch === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- Expired first, always. It is the only list here that costs money every
         day it is ignored. --}}
    <div class="card mb-5 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                <h3 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-400">
                        <x-icon name="alert" class="h-4 w-4" />
                    </span>
                    Already expired
                </h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Still on the shelf, but it must not be sold. Write it off with a stock adjustment.
                </p>
            </div>
            <span class="badge-red">{{ $expired->total() }}</span>
        </div>

        @include('app.inventory.batch-table', ['batches' => $expired, 'canSeeCost' => $canSeeCost, 'empty' => 'Nothing has expired. Good.'])

        @if ($expired->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $expired->links() }}</div>
        @endif
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                <h3 class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                        <x-icon name="clock" class="h-4 w-4" />
                    </span>
                    Expiring within {{ $window }} days
                </h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Still good — sell or discount these first. Stock leaves by earliest expiry automatically.
                </p>
            </div>
            <span class="badge-amber">{{ $expiring->total() }}</span>
        </div>

        @include('app.inventory.batch-table', ['batches' => $expiring, 'canSeeCost' => $canSeeCost, 'empty' => 'Nothing is close to expiring.'])

        @if ($expiring->hasPages())
            <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">{{ $expiring->links() }}</div>
        @endif
    </div>

</x-layouts.app>
