@php
    $subscriberCount = $plan->subscriptions()->whereNull('superseded_at')->count();
@endphp

<x-layouts.admin :title="'Edit · '.$plan->name">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('admin.plans.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
                <x-icon name="arrow-left" class="h-4 w-4" /> Back to plans
            </a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">{{ $plan->name }}</h2>
                <span class="{{ $plan->is_active ? 'badge-green' : 'badge-slate' }}">{{ $plan->is_active ? 'Active' : 'Inactive' }}</span>
                @unless ($plan->is_public)
                    <span class="badge-slate">Private</span>
                @endunless
            </div>
        </div>

        <a href="{{ route('admin.subscriptions.index', ['plan' => $plan->id]) }}" class="btn btn-secondary">
            <x-icon name="users" class="h-4 w-4" />
            {{ $subscriberCount }} subscriber(s)
        </a>
    </div>

    @if ($subscriberCount > 0)
        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
            <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="text-amber-800 dark:text-amber-300">
                <p class="font-semibold text-amber-900 dark:text-amber-200">This plan is live</p>
                <p class="mt-0.5">
                    {{ $subscriberCount }} business(es) are subscribed. Removing a feature or lowering a quota takes
                    effect for all of them the moment you save — a tenant already over a lowered quota keeps its
                    existing records but cannot add more.
                </p>
            </div>
        </div>
    @endif

    @include('admin.plans.form', [
        'action' => route('admin.plans.update', $plan),
        'method' => 'PUT',
    ])

</x-layouts.admin>
