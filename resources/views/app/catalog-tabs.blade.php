{{--
    One nav strip across the four catalogue screens (#195: fewer clicks).

    Each tab is permission-checked, so a role with `products.view` but not
    `catalog.manage` simply sees one tab rather than three that would refuse it.
--}}
@php
    use App\Support\PermissionRegistry;

    $tabs = [
        ['label' => 'Products',   'route' => 'app.products.index',   'permission' => PermissionRegistry::PRODUCTS_VIEW],
        ['label' => 'Categories', 'route' => 'app.categories.index', 'permission' => PermissionRegistry::CATALOG_MANAGE],
        ['label' => 'Brands',     'route' => 'app.brands.index',     'permission' => PermissionRegistry::CATALOG_MANAGE],
        ['label' => 'Units',      'route' => 'app.units.index',      'permission' => PermissionRegistry::CATALOG_MANAGE],
    ];
@endphp

<div class="mb-5 flex flex-wrap items-center gap-1 border-b border-slate-200 dark:border-slate-800">
    @foreach ($tabs as $tab)
        @can($tab['permission'])
            @php $active = request()->routeIs(str_replace('.index', '.*', $tab['route'])); @endphp
            <a href="{{ route($tab['route']) }}"
               @class([
                   '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                   'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $active,
                   'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => ! $active,
               ])>{{ $tab['label'] }}</a>
        @endcan
    @endforeach
</div>
