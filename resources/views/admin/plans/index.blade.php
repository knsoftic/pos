<x-layouts.admin title="Plans">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ $plans->count() }} plan(s) · {{ $featureCount }} features available to assign.
                Prices, features and quotas are all data — nothing here is hardcoded.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.plans.matrix') }}" class="btn btn-secondary">
                <x-icon name="sliders" class="h-4 w-4" /> Compare
            </a>
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary">
                <x-icon name="plus" class="h-4 w-4" /> New plan
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($plans as $plan)
            <div class="card flex flex-col p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate font-bold text-slate-900 dark:text-white">{{ $plan->name }}</h3>
                            @if ($plan->badge)
                                <span class="badge-brand">{{ $plan->badge }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-400">{{ $plan->slug }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span class="{{ $plan->is_active ? 'badge-green' : 'badge-slate' }}">
                            {{ $plan->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        @unless ($plan->is_public)
                            <span class="badge-slate">Private</span>
                        @endunless
                    </div>
                </div>

                @if ($plan->description)
                    <p class="mt-3 line-clamp-2 text-sm text-slate-600 dark:text-slate-400">{{ $plan->description }}</p>
                @endif

                {{-- Prices: one row per billing cycle (#175) --}}
                <div class="mt-4 space-y-1.5">
                    @forelse ($plan->activePrices() as $price)
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="text-slate-500 dark:text-slate-400">{{ $price->billing_cycle->label() }}</span>
                            <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ $price->formatted() }}
                                <span class="text-xs font-normal text-slate-400">{{ $price->periodLabel() }}</span>
                            </span>
                        </div>
                    @empty
                        <p class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                            No prices configured — nobody can be subscribed to this plan.
                        </p>
                    @endforelse
                </div>

                <dl class="mt-4 grid grid-cols-3 gap-2 border-t border-slate-100 pt-4 text-center dark:border-slate-800">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-slate-400">Subscribers</dt>
                        <dd class="mt-0.5 text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ $plan->subscriptions_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-slate-400">Trial</dt>
                        <dd class="mt-0.5 text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ $plan->trialDays() }}<span class="text-xs font-normal text-slate-400">d</span></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wide text-slate-400">Grace</dt>
                        <dd class="mt-0.5 text-lg font-bold tabular-nums text-slate-900 dark:text-white">{{ $plan->graceDays() }}<span class="text-xs font-normal text-slate-400">d</span></dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <a href="{{ route('admin.plans.edit', $plan) }}" class="btn btn-secondary flex-1">
                        <x-icon name="pencil" class="h-4 w-4" /> Edit
                    </a>

                    <form method="POST" action="{{ route('admin.plans.toggle', $plan) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost" title="{{ $plan->is_active ? 'Deactivate' : 'Activate' }}">
                            <x-icon :name="$plan->is_active ? 'ban' : 'check-circle'" class="h-4 w-4" />
                        </button>
                    </form>

                    {{-- Archive, not delete. Refused server-side while anyone is
                         still subscribed. #104 --}}
                    <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}"
                          onsubmit="return confirm('Archive &quot;{{ $plan->name }}&quot;? It stays in the database and existing subscriptions keep working.');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10" title="Archive">
                            <x-icon name="archive" class="h-4 w-4" />
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="card col-span-full p-10 text-center">
                <x-icon name="products" class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" />
                <p class="mt-3 font-medium text-slate-700 dark:text-slate-300">No plans yet</p>
                <p class="mt-1 text-sm text-slate-400">Run the seeders or create your first plan.</p>
                <a href="{{ route('admin.plans.create') }}" class="btn btn-primary mt-4 inline-flex">
                    <x-icon name="plus" class="h-4 w-4" /> New plan
                </a>
            </div>
        @endforelse
    </div>

</x-layouts.admin>
