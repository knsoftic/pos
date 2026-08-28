{{-- Shared by the parent and child rows of the category table. --}}
<div class="flex items-center justify-end gap-1">
    <a href="{{ route('app.categories.edit', $category) }}" class="btn btn-ghost !px-2" title="Edit">
        <x-icon name="pencil" class="h-4 w-4" />
    </a>

    @if ($category->canBeDeleted())
        <form method="POST" action="{{ route('app.categories.destroy', $category) }}"
              onsubmit="return confirm('Delete {{ $category->name }}?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400" title="Delete">
                <x-icon name="trash" class="h-4 w-4" />
            </button>
        </form>
    @else
        <span class="btn btn-ghost !px-2 cursor-not-allowed text-slate-300 dark:text-slate-600"
              title="In use — switch it off instead">
            <x-icon name="trash" class="h-4 w-4" />
        </span>
    @endif
</div>
