<x-layouts.app title="Notifications">

    <x-flash />

    <div class="card mb-5 p-5">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">What needs you</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            <strong>Alerts</strong> are conditions — they disappear the moment the shop fixes them, which is why
            they cannot be put away. <strong>Notices</strong> come from {{ config('brand.name') }} and can be
            dismissed once you have read them.
        </p>
    </div>

    <div class="space-y-3">
        @forelse ($items as $item)
            @php
                $tint = [
                    'danger' => 'border-rose-200 dark:border-rose-500/30',
                    'warning' => 'border-amber-200 dark:border-amber-500/30',
                ][$item['level']] ?? 'border-slate-200 dark:border-slate-800';

                $dot = [
                    'danger' => 'bg-rose-500',
                    'warning' => 'bg-amber-500',
                ][$item['level']] ?? 'bg-brand-500';
            @endphp

            <div class="card border {{ $tint }} p-5">
                <div class="flex items-start gap-3">
                    <span class="mt-2 h-2.5 w-2.5 shrink-0 rounded-full {{ $dot }}"></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-slate-900 dark:text-white">{{ $item['title'] }}</h3>
                            <span class="badge-slate">{{ $item['type'] === 'announcement' ? 'Notice' : 'Alert' }}</span>
                        </div>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $item['body'] }}</p>

                        @if ($item['href'])
                            <a href="{{ $item['href'] }}" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                Take a look <x-icon name="arrow-right" class="h-4 w-4" />
                            </a>
                        @endif
                    </div>

                    @if ($item['dismissible'] ?? false)
                        <form method="POST" action="{{ route('app.notifications.dismiss', $item['id']) }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost !px-3 !py-1.5 text-xs">Dismiss</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div class="card p-12 text-center">
                <p class="text-slate-500 dark:text-slate-400">All clear. Nothing needs you right now.</p>
            </div>
        @endforelse
    </div>

</x-layouts.app>
