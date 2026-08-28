<x-layouts.admin :title="'Edit · '.$business->name">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('admin.businesses.show', $business) }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $business->name }}
        </a>
    </div>

    <form method="POST" action="{{ route('admin.businesses.update', $business) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('admin.businesses.fields')

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('admin.businesses.show', $business) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-4 w-4" /> Save changes
            </button>
        </div>
    </form>

    {{-- Kept OUTSIDE the update form — a form inside a form is invalid HTML and
         the inner one silently stops submitting. --}}
    <div class="card mt-6 border-rose-200 p-5 dark:border-rose-500/30">
        <h3 class="font-semibold text-slate-900 dark:text-white">Archive this business</h3>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Archiving hides the tenant from the console and stops all access. Nothing is deleted — sales, payments and
            audit history are retained, because financial records must stay auditable (#104 / #133). Suspend first if
            you only need to pause them.
        </p>

        <form method="POST" action="{{ route('admin.businesses.destroy', $business) }}" class="mt-4"
              onsubmit="return confirm('Archive &quot;{{ $business->name }}&quot;? All of its data is retained and it can be restored later.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">
                <x-icon name="archive" class="h-4 w-4" /> Archive business
            </button>
        </form>
    </div>

</x-layouts.admin>
