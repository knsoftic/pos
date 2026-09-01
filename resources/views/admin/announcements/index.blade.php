<x-layouts.admin title="Announcements">

    <x-flash />

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        {{-- ─────────────────────────── write one ──────────────────────────── --}}
        <div class="card h-fit p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">New announcement</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Goes to the bell in every shop's workspace. Give it dates and it appears and disappears on its
                own — "maintenance on Sunday" is worse than useless on Monday.
            </p>

            <form method="POST" action="{{ route('admin.announcements.store') }}" class="mt-4 space-y-3">
                @csrf

                <div>
                    <label for="title" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Title</label>
                    <input id="title" name="title" type="text" required maxlength="150"
                           value="{{ old('title') }}" placeholder="Scheduled maintenance" class="input" />
                    @error('title') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="body" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Message</label>
                    <textarea id="body" name="body" rows="3" required maxlength="2000" class="input">{{ old('body') }}</textarea>
                    @error('body') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="level" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">How loud</label>
                    <select id="level" name="level" class="input">
                        <option value="info">Information</option>
                        <option value="warning">Warning</option>
                        <option value="danger">Urgent</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="starts_at" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">From</label>
                        <input id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" class="input" />
                    </div>
                    <div>
                        <label for="ends_at" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Until</label>
                        <input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" class="input" />
                        @error('ends_at') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <label class="flex cursor-pointer items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="is_dismissible" value="1" checked
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800" />
                    <span>
                        People may dismiss it
                        <span class="block text-xs text-slate-400">Untick for an outage notice that has to stay put.</span>
                    </span>
                </label>

                <input type="hidden" name="is_active" value="1" />

                <button type="submit" class="btn btn-primary w-full">
                    <x-icon name="plus" class="h-4 w-4" /> Publish
                </button>
            </form>
        </div>

        {{-- ───────────────────────────── the list ─────────────────────────── --}}
        <div class="space-y-4 xl:col-span-2">
            @forelse ($announcements as $announcement)
                <div x-data="{ editing: false }" class="card p-5">
                    <div x-show="! editing">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-slate-900 dark:text-white">{{ $announcement->title }}</h3>
                                    <span class="{{ $announcement->badgeClass() }}">{{ Str::headline($announcement->level) }}</span>

                                    @if ($announcement->isLive())
                                        <span class="badge-green">Live</span>
                                    @elseif (! $announcement->is_active)
                                        <span class="badge-slate">Off</span>
                                    @elseif ($announcement->starts_at?->isFuture())
                                        <span class="badge-slate">Scheduled</span>
                                    @else
                                        <span class="badge-slate">Finished</span>
                                    @endif

                                    @unless ($announcement->is_dismissible)
                                        <span class="badge-amber">Cannot be dismissed</span>
                                    @endunless
                                </div>

                                <p class="mt-2 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $announcement->body }}</p>

                                <p class="mt-2 text-xs text-slate-400">
                                    {{ $announcement->starts_at ? 'From '.$announcement->starts_at->format('d M Y, H:i') : 'From publication' }}
                                    ·
                                    {{ $announcement->ends_at ? 'until '.$announcement->ends_at->format('d M Y, H:i') : 'until switched off' }}
                                    · dismissed by {{ number_format($announcement->dismissed_by_count) }}
                                    @if ($announcement->author)
                                        · {{ $announcement->author->name }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex items-center gap-1">
                                <button type="button" @click="editing = true"
                                        class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800"
                                        title="Edit">
                                    <x-icon name="pencil" class="h-4 w-4" />
                                </button>
                                <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}"
                                      onsubmit="return confirm('Delete this announcement? Switching it off is usually what you want.')">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                            class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10"
                                            title="Delete">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <form x-show="editing" x-cloak method="POST"
                          action="{{ route('admin.announcements.update', $announcement) }}" class="space-y-3">
                        @csrf @method('PUT')

                        <input name="title" type="text" required maxlength="150" value="{{ $announcement->title }}" class="input" />
                        <textarea name="body" rows="3" required maxlength="2000" class="input">{{ $announcement->body }}</textarea>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <select name="level" class="input">
                                @foreach (['info' => 'Information', 'warning' => 'Warning', 'danger' => 'Urgent'] as $value => $label)
                                    <option value="{{ $value }}" @selected($announcement->level === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input name="starts_at" type="datetime-local" class="input"
                                   value="{{ $announcement->starts_at?->format('Y-m-d\TH:i') }}" />
                            <input name="ends_at" type="datetime-local" class="input"
                                   value="{{ $announcement->ends_at?->format('Y-m-d\TH:i') }}" />
                        </div>

                        <div class="flex flex-wrap items-center gap-4">
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="is_active" value="1" @checked($announcement->is_active)
                                       class="h-4 w-4 rounded border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800" /> Active
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="is_dismissible" value="1" @checked($announcement->is_dismissible)
                                       class="h-4 w-4 rounded border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800" /> Dismissible
                            </label>

                            <div class="ml-auto flex items-center gap-2">
                                <button type="button" class="btn btn-ghost" @click="editing = false">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            @empty
                <div class="card p-10 text-center text-slate-400">
                    Nothing published yet. Shops see these in the bell in their workspace.
                </div>
            @endforelse

            @if ($announcements->hasPages())
                <div>{{ $announcements->links() }}</div>
            @endif
        </div>
    </div>

</x-layouts.admin>
