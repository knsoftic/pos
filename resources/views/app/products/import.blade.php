<x-layouts.app title="Import & export">

    <x-flash />
    @include('app.catalog-tabs')

    @if (session('import_errors'))
        <div class="card mb-5 border-rose-200 bg-rose-50 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
            <p class="font-semibold text-rose-800 dark:text-rose-300">Nothing was imported</p>
            <p class="mt-1 text-sm text-rose-700/90 dark:text-rose-300/80">
                The whole file is rolled back when any row fails — a half-imported catalogue is harder to fix than
                one that never imported.
            </p>
            <ul class="mt-3 space-y-1 text-sm text-rose-700 dark:text-rose-300">
                @foreach (array_slice(session('import_errors'), 0, 20) as $error)
                    <li class="font-mono text-xs">{{ $error }}</li>
                @endforeach
            </ul>
            @if (count(session('import_errors')) > 20)
                <p class="mt-2 text-xs text-rose-600 dark:text-rose-400">
                    …and {{ count(session('import_errors')) - 20 }} more.
                </p>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

        {{-- ------------------------------------------------------- import --}}
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Import products</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Upload a CSV. A row whose SKU already exists <strong>updates</strong> that product instead of adding a
                second one, so a corrected price list can simply be re-uploaded.
            </p>

            <form method="POST" action="{{ route('app.products.import.store') }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf

                <input type="file" name="file" accept=".csv,text/csv" required
                       class="input !py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:file:bg-slate-700 dark:file:text-slate-200" />

                @error('file')
                    <p class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror

                <button type="submit" class="btn btn-primary w-full">
                    <x-icon name="products" class="h-4 w-4" /> Import
                </button>
            </form>

            <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                <p class="mb-2 text-xs font-medium text-slate-500 dark:text-slate-400">Columns the file understands</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($columns as $column)
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $column }}</code>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-400">
                    Only <code>name</code> is required. Categories and brands are created if they do not exist; units
                    are not — an invented unit of measure is a data error, so create those first.
                </p>

                <a href="{{ route('app.products.import.template') }}" class="btn btn-ghost mt-3 !px-0 text-brand-600 dark:text-brand-400">
                    <x-icon name="arrow-right" class="h-4 w-4" /> Download the template
                </a>
            </div>
        </div>

        {{-- ------------------------------------------------------- export --}}
        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">Export products</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                The whole catalogue as CSV — the same columns the import reads, so an export can be edited and sent
                straight back.
            </p>

            @cannot(\App\Support\PermissionRegistry::PRODUCTS_VIEW_COST)
                <p class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    Cost prices are hidden for your role, so they are left out of the file too.
                </p>
            @endcannot

            <a href="{{ route('app.products.export') }}" class="btn btn-secondary mt-4 w-full">
                <x-icon name="archive" class="h-4 w-4" /> Download CSV
            </a>
        </div>
    </div>

</x-layouts.app>
