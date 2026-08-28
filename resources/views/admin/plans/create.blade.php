<x-layouts.admin title="New plan">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('admin.plans.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to plans
        </a>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            Everything below is stored in the database — prices, features and quotas are read at runtime, so no code
            changes are needed to launch a new plan.
        </p>
    </div>

    @include('admin.plans.form', [
        'action' => route('admin.plans.store'),
        'method' => 'POST',
    ])

</x-layouts.admin>
