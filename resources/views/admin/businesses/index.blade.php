<x-layouts.admin title="Businesses">

    <x-flash />

    {{-- ---------------------------------------------------------- stat cards --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Total tenants', 'value' => $stats['total'], 'icon' => 'building', 'tone' => 'text-brand-600 dark:text-brand-400'],
            ['label' => 'Active', 'value' => $stats['active'], 'icon' => 'check-circle', 'tone' => 'text-emerald-600 dark:text-emerald-400'],
            ['label' => 'Suspended', 'value' => $stats['suspended'], 'icon' => 'ban', 'tone' => 'text-amber-600 dark:text-amber-400'],
            ['label' => 'On trial', 'value' => $stats['trialing'], 'icon' => 'zap', 'tone' => 'text-slate-600 dark:text-slate-300'],
        ] as $card)
            <div class="card p-4">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs uppercase tracking-wide text-slate-400">{{ $card['label'] }}</p>
                    <x-icon :name="$card['icon']" class="h-4 w-4 {{ $card['tone'] }}" />
                </div>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format($card['value']) }}</p>
            </div>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('admin.businesses.index') }}" class="card mb-4 p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <label class="label" for="search">Search</label>
                <input id="search" name="search" type="search" value="{{ $filters['search'] }}"
                       class="input" placeholder="Name, slug, email or phone">
            </div>

            <div>
                <label class="label" for="status">Account status</label>
                <select id="status" name="status" class="input">
                    <option value="">Any</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="plan">Plan</label>
                <select id="plan" name="plan" class="input">
                    <option value="">Any</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->slug }}" @selected($filters['plan'] === $plan->slug)>{{ $plan->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="subscription">Subscription</label>
                <select id="subscription" name="subscription" class="input">
                    <option value="">Any</option>
                    @foreach (\App\Enums\SubscriptionStatus::options() as $value => $label)
                        <option value="{{ $value }}" @selected($filters['subscription'] === $value)>{{ $label }}</option>
                    @endforeach
                    <option value="none" @selected($filters['subscription'] === 'none')>No subscription</option>
                </select>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            {{-- Honest about the one filter that cannot be done in SQL. --}}
            <p class="text-xs text-slate-400">
                @if ($filters['subscription'] !== '')
                    Subscription state is derived from dates, so that filter narrows the current page only.
                @endif
            </p>
            <div class="flex items-center gap-2">
                @if (array_filter($filters))
                    <a href="{{ route('admin.businesses.index') }}" class="btn btn-ghost">Clear</a>
                @endif
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-4 w-4" /> Apply
                </button>
                <a href="{{ route('admin.businesses.create') }}" class="btn btn-primary">
                    <x-icon name="plus" class="h-4 w-4" /> New business
                </a>
            </div>
        </div>
    </form>

    {{-- ---------------------------------------------------------------- list --}}
    <div class="table-wrap">
        <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr>
                    <th class="th text-left">Business</th>
                    <th class="th text-left">Plan</th>
                    <th class="th text-left">Subscription</th>
                    <th class="th text-left">Renews / expires</th>
                    <th class="th text-right">Users</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($businesses as $business)
                    @php
                        $subscription = $business->currentSubscription;
                        $status = $subscription?->effectiveStatus();
                    @endphp
                    <tr>
                        <td class="td">
                            <a href="{{ route('admin.businesses.show', $business) }}" class="font-medium text-slate-900 hover:underline dark:text-white">
                                {{ $business->name }}
                            </a>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2">
                                <span class="text-xs text-slate-400">{{ $business->slug }}</span>
                                <span class="{{ $business->statusBadgeClass() }}">{{ $statuses[$business->status] ?? $business->status }}</span>
                            </div>
                        </td>

                        <td class="td">
                            @if ($subscription?->plan)
                                <span class="text-slate-700 dark:text-slate-300">{{ $subscription->plan->name }}</span>
                                <span class="block text-xs text-slate-400">{{ $subscription->billing_cycle->label() }}</span>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>

                        <td class="td">
                            @if ($status)
                                <span class="{{ $status->badgeClass() }}">{{ $status->label() }}</span>
                                @if ($subscription->isInGrace())
                                    <span class="badge-amber ml-1">Grace</span>
                                @endif
                            @else
                                <span class="badge-slate">None</span>
                            @endif
                        </td>

                        <td class="td whitespace-nowrap">
                            @if ($subscription === null)
                                <span class="text-xs text-slate-400">—</span>
                            @elseif ($subscription->neverExpires())
                                <span class="text-xs text-slate-500 dark:text-slate-400">Never expires</span>
                            @else
                                @php $days = $subscription->daysRemaining(); @endphp
                                <span class="text-slate-700 dark:text-slate-300">{{ $subscription->ends_at?->format('d M Y') ?? '—' }}</span>
                                @if ($days !== null)
                                    <span @class([
                                        'block text-xs',
                                        'text-rose-600 dark:text-rose-400' => $days <= 0,
                                        'text-amber-600 dark:text-amber-400' => $days > 0 && $days <= $subscription->expiryWarningThreshold(),
                                        'text-slate-400' => $days > $subscription->expiryWarningThreshold(),
                                    ])>
                                        {{ $days <= 0 ? 'Overdue' : $days.' day(s) left' }}
                                    </span>
                                @endif
                            @endif
                        </td>

                        <td class="td text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $business->users_count }}</td>

                        <td class="td">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('admin.businesses.overrides.index', $business) }}" class="btn btn-ghost !px-2" title="Feature &amp; quota overrides">
                                    <x-icon name="sliders" class="h-4 w-4" />
                                </a>
                                <a href="{{ route('admin.businesses.edit', $business) }}" class="btn btn-ghost !px-2" title="Edit">
                                    <x-icon name="pencil" class="h-4 w-4" />
                                </a>
                                @if ($business->isActive())
                                    <form method="POST" action="{{ route('admin.businesses.impersonate', $business) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost !px-2" title="Sign in as the owner">
                                            <x-icon name="user-check" class="h-4 w-4" />
                                        </button>
                                    </form>
                                @endif
                                <a href="{{ route('admin.businesses.show', $business) }}" class="btn btn-secondary !px-2" title="Open">
                                    <x-icon name="arrow-right" class="h-4 w-4" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="td py-10 text-center" colspan="6">
                            <x-icon name="building" class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" />
                            <p class="mt-3 font-medium text-slate-700 dark:text-slate-300">No businesses match</p>
                            <p class="mt-1 text-sm text-slate-400">Adjust the filters, or onboard your first tenant.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($businesses->hasPages())
        <div class="mt-4">{{ $businesses->links() }}</div>
    @endif

</x-layouts.admin>
