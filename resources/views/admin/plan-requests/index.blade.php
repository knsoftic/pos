<x-layouts.admin title="Plan requests">

    <x-flash />

    @php
        $tabs = [
            'pending' => 'Waiting',
            'done' => 'Done',
            'declined' => 'Declined',
            '' => 'All',
        ];
    @endphp

    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Plan requests</h2>
                <p class="mt-1 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                    Shops asking to change plan. {{-- Said plainly, because the button below looks like it
                        might do more than it does. --}}
                    <strong>Marking one done does not move the shop</strong> — do that on its subscription,
                    once the money has arrived.
                </p>
            </div>

            @if ($pendingCount > 0)
                <span class="badge-amber shrink-0">{{ $pendingCount }} waiting</span>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
            @foreach ($tabs as $key => $label)
                @php $active = (string) ($status?->value ?? '') === (string) $key; @endphp
                <a href="{{ route('admin.plan-requests.index', $key === '' ? [] : ['status' => $key]) }}"
                   @class([
                       '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                       'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $active,
                       'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => ! $active,
                   ])>{{ $label }}</a>
            @endforeach
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
                        <th class="th">Shop</th>
                        <th class="th">Wants</th>
                        <th class="th">Currently on</th>
                        <th class="th">Asked</th>
                        <th class="th">Status</th>
                        <th class="th text-right">Answer</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($requests as $request)
                        <tr>
                            <td class="td">
                                <a href="{{ route('admin.businesses.show', $request->business_id) }}"
                                   class="font-medium text-brand-700 hover:underline dark:text-brand-300">
                                    {{ $request->business?->name ?? '—' }}
                                </a>
                                @if ($request->requested_by_name)
                                    <span class="block text-xs text-slate-400">{{ $request->requested_by_name }}</span>
                                @endif
                            </td>
                            <td class="td font-semibold text-slate-900 dark:text-white">
                                {{ $request->plan?->name ?? '—' }}
                                @if ($request->billing_cycle)
                                    <span class="block text-xs font-normal text-slate-400">{{ $request->billing_cycle->label() }}</span>
                                @endif
                            </td>
                            <td class="td text-slate-600 dark:text-slate-300">{{ $request->current_plan_name ?: '—' }}</td>
                            <td class="td text-slate-500 dark:text-slate-400">
                                {{ $request->created_at?->diffForHumans() }}
                                @if ($request->handled_at)
                                    <span class="block text-xs text-slate-400">
                                        answered {{ $request->handled_at->diffForHumans() }}{{ $request->handler?->name ? ' by '.$request->handler->name : '' }}
                                    </span>
                                @endif
                            </td>
                            <td class="td"><span class="{{ $request->status->badgeClass() }}">{{ $request->status->label() }}</span></td>
                            <td class="td">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($request->status->isOpen())
                                        <form method="POST" action="{{ route('admin.plan-requests.update', $request) }}">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="status" value="{{ \App\Enums\PlanRequestStatus::Done->value }}" />
                                            <button type="submit" class="btn btn-secondary btn-sm">Done</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.plan-requests.update', $request) }}">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="status" value="{{ \App\Enums\PlanRequestStatus::Declined->value }}" />
                                            <button type="submit" class="btn btn-ghost btn-sm">Decline</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.plan-requests.update', $request) }}">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="status" value="{{ \App\Enums\PlanRequestStatus::Pending->value }}" />
                                            <button type="submit" class="btn btn-ghost btn-sm">Reopen</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                                Nothing here. Shops ask from their own Billing → Compare plans screen.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $requests->links() }}</div>
    </div>

</x-layouts.admin>
