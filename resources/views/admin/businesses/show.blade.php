@php
    use Illuminate\Support\Str;

    $status = $subscription?->effectiveStatus();
    $overrideCount = $featureOverrides->count() + $limitOverrides->count();
    $notes = $business->notes;
@endphp

<x-layouts.admin :title="$business->name">

    <x-flash />

    {{-- ================================ HEADER ================================ --}}
    <div class="mb-5">
        <a href="{{ route('admin.businesses.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Businesses
        </a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white">{{ $business->name }}</h2>
                    <span class="{{ $business->statusBadgeClass() }}">{{ \App\Models\Business::statusOptions()[$business->status] ?? $business->status }}</span>
                    @if ($status)
                        <span class="{{ $status->badgeClass() }}">{{ $status->label() }}</span>
                    @else
                        <span class="badge-red">No subscription</span>
                    @endif
                    @if ($subscription?->isInGrace())
                        <span class="badge-amber">In grace · {{ $subscription->graceDaysRemaining() }}d left</span>
                    @endif
                    @if ($overrideCount > 0)
                        <span class="badge-brand">{{ $overrideCount }} override(s)</span>
                    @endif
                </div>
                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                    <span>{{ $business->slug }}</span>
                    @if ($business->email)<span>{{ $business->email }}</span>@endif
                    @if ($business->phone)<span>{{ $business->phone }}</span>@endif
                    <span>{{ $business->timezone }}</span>
                    <span>Joined {{ $business->created_at?->format('d M Y') }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.businesses.overrides.index', $business) }}" class="btn btn-secondary">
                    <x-icon name="sliders" class="h-4 w-4" /> Overrides
                </a>
                <a href="{{ route('admin.businesses.edit', $business) }}" class="btn btn-secondary">
                    <x-icon name="pencil" class="h-4 w-4" /> Edit
                </a>

                @if ($business->isActive())
                    {{-- Signs the operator into the tenant on the web guard while
                         the admin guard stays logged in — see ImpersonationController. --}}
                    <form method="POST" action="{{ route('admin.businesses.impersonate', $business) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="user-check" class="h-4 w-4" /> Sign in as owner
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.businesses.activate', $business) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="check-circle" class="h-4 w-4" /> Reactivate
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">

        {{-- ============================ MAIN COLUMN ============================ --}}
        <div class="space-y-5 xl:col-span-2">

            {{-- ---------------------------- subscription ---------------------------- --}}
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Subscription</h3>
                    <a href="{{ route('admin.subscriptions.index') }}" class="text-xs text-brand-600 hover:underline dark:text-brand-400">All subscriptions</a>
                </div>

                @if ($subscription === null)
                    <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                        This tenant has no subscription, so nobody can use the app. Assign a plan below.
                    </p>
                @else
                    <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">Plan</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">
                                @if ($subscription->plan)
                                    <a href="{{ route('admin.plans.edit', $subscription->plan) }}" class="hover:underline">{{ $subscription->plan->name }}</a>
                                @else
                                    <span class="text-rose-600">Plan missing</span>
                                @endif
                            </dd>
                            <dd class="text-xs text-slate-400">{{ $subscription->billing_cycle->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">Price</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-slate-900 dark:text-white">{{ $subscription->formattedPrice() }}</dd>
                            <dd class="text-xs text-slate-400">{{ $subscription->currency }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">Started</dt>
                            <dd class="mt-0.5 tabular-nums text-slate-700 dark:text-slate-300">{{ $subscription->starts_at?->format('d M Y') ?? '—' }}</dd>
                            @if ($subscription->trial_ends_at)
                                <dd class="text-xs text-slate-400">Trial to {{ $subscription->trial_ends_at->format('d M Y') }}</dd>
                            @endif
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">
                                {{ $subscription->neverExpires() ? 'Expiry' : 'Expires' }}
                            </dt>
                            <dd class="mt-0.5 tabular-nums text-slate-700 dark:text-slate-300">
                                {{ $subscription->neverExpires() ? 'Never' : ($subscription->ends_at?->format('d M Y') ?? '—') }}
                            </dd>
                            @php $days = $subscription->daysRemaining(); @endphp
                            @if ($days !== null)
                                <dd @class([
                                    'text-xs',
                                    'text-rose-600 dark:text-rose-400' => $days <= 0,
                                    'text-amber-600 dark:text-amber-400' => $days > 0 && $days <= $subscription->expiryWarningThreshold(),
                                    'text-slate-400' => $days > $subscription->expiryWarningThreshold(),
                                ])>
                                    {{ $days <= 0 ? 'Lapsed' : $days.' day(s) left' }}
                                </dd>
                            @endif
                        </div>
                    </dl>

                    @if ($subscription->isCancelled())
                        <div class="mt-4 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
                            <p class="font-medium text-slate-700 dark:text-slate-300">
                                Cancelled {{ $subscription->cancelled_at?->format('d M Y') }}
                            </p>
                            @if ($subscription->cancellation_reason)
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $subscription->cancellation_reason }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($subscription->notes)
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ $subscription->notes }}</p>
                    @endif
                @endif

                {{-- Each action is its own form, siblings not nested, so the
                     browser posts exactly the one the operator opened. --}}
                <div class="mt-5 space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800">

                    {{-- assign / change plan --}}
                    <details class="group rounded-xl border border-slate-200 dark:border-slate-800" @if ($errors->has('plan_id')) open @endif>
                        <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-slate-200">
                            <span class="flex items-center gap-2">
                                <x-icon name="credit-card" class="h-4 w-4 text-brand-600 dark:text-brand-400" />
                                {{ $subscription === null ? 'Assign a plan' : 'Change plan' }}
                            </span>
                            <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open:rotate-180" />
                        </summary>

                        <form method="POST" action="{{ route('admin.businesses.subscription.store', $business) }}"
                              class="border-t border-slate-100 p-3 dark:border-slate-800">
                            @csrf
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="label" for="sub_plan_id">Plan</label>
                                    <select id="sub_plan_id" name="plan_id" required class="input">
                                        @foreach ($plans as $plan)
                                            <option value="{{ $plan->id }}" @selected(old('plan_id', $subscription?->plan_id) == $plan->id)>{{ $plan->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('plan_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="label" for="sub_cycle">Billing cycle</label>
                                    <select id="sub_cycle" name="billing_cycle" required class="input">
                                        @foreach ($cycles as $cycle)
                                            <option value="{{ $cycle->value }}" @selected(old('billing_cycle', $subscription?->billing_cycle->value) === $cycle->value)>{{ $cycle->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('billing_cycle') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="label" for="sub_mode">How</label>
                                    <select id="sub_mode" name="mode" required class="input">
                                        @if ($subscription !== null)
                                            <option value="change">Change plan — credit the unused days</option>
                                        @endif
                                        <option value="assign">Assign — fresh paid period, no credit</option>
                                        <option value="trial">Trial — free, no charge</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="label" for="sub_price">Price override</label>
                                    <input id="sub_price" name="price" type="number" step="0.01" min="0"
                                           class="input tabular-nums" placeholder="Use the plan's price">
                                    <p class="mt-1 text-xs text-slate-400">A negotiated one-off amount. Blank = plan price.</p>
                                </div>

                                <div>
                                    <label class="label" for="sub_trial_days">Trial days</label>
                                    <input id="sub_trial_days" name="trial_days" type="number" min="1" max="365"
                                           class="input tabular-nums" placeholder="Plan default">
                                    <p class="mt-1 text-xs text-slate-400">Only used when "Trial" is chosen.</p>
                                </div>

                                <div class="flex items-end">
                                    <label class="flex items-start gap-2.5 pb-2">
                                        <input type="hidden" name="credit_remaining_days" value="0">
                                        <input type="checkbox" name="credit_remaining_days" value="1" checked
                                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span class="text-sm">
                                            <span class="font-medium text-slate-800 dark:text-slate-200">Credit unused days</span>
                                            <span class="block text-xs text-slate-400">Carry the remaining days onto the new period.</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="label" for="sub_notes">Note</label>
                                    <input id="sub_notes" name="notes" type="text" maxlength="500" class="input"
                                           placeholder="Why this change was made — stored on the subscription.">
                                </div>
                            </div>

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <p class="text-xs text-slate-400">
                                    The current period is superseded, not overwritten — the old row stays as history.
                                </p>
                                <button type="submit" class="btn btn-primary">Apply</button>
                            </div>
                        </form>
                    </details>

                    @if ($subscription !== null)
                        {{-- renew --}}
                        <details class="group rounded-xl border border-slate-200 dark:border-slate-800">
                            <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-slate-200">
                                <span class="flex items-center gap-2">
                                    <x-icon name="refresh" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" /> Renew
                                </span>
                                <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open:rotate-180" />
                            </summary>
                            <form method="POST" action="{{ route('admin.businesses.subscription.renew', $business) }}"
                                  class="border-t border-slate-100 p-3 dark:border-slate-800">
                                @csrf
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="label" for="renew_cycle">Cycle</label>
                                        <select id="renew_cycle" name="billing_cycle" class="input">
                                            <option value="">Same as now ({{ $subscription->billing_cycle->label() }})</option>
                                            @foreach ($cycles as $cycle)
                                                <option value="{{ $cycle->value }}">{{ $cycle->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="label" for="renew_price">Price</label>
                                        <input id="renew_price" name="price" type="number" step="0.01" min="0"
                                               class="input tabular-nums" placeholder="Plan price">
                                    </div>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <p class="text-xs text-slate-400">
                                        Renewal starts where the current period ends, so no paid time is lost.
                                    </p>
                                    <button type="submit" class="btn btn-primary">Renew</button>
                                </div>
                            </form>
                        </details>

                        {{-- extend / trial days --}}
                        <details class="group rounded-xl border border-slate-200 dark:border-slate-800">
                            <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-slate-200">
                                <span class="flex items-center gap-2">
                                    <x-icon name="calendar" class="h-4 w-4 text-brand-600 dark:text-brand-400" /> Extend expiry
                                </span>
                                <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open:rotate-180" />
                            </summary>
                            <div class="space-y-3 border-t border-slate-100 p-3 dark:border-slate-800">
                                <form method="POST" action="{{ route('admin.businesses.subscription.extend', $business) }}"
                                      class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <div>
                                        <label class="label" for="extend_days">Add days</label>
                                        <input id="extend_days" name="days" type="number" min="1" max="3650" required
                                               class="input w-28 tabular-nums" placeholder="7">
                                    </div>
                                    <div class="min-w-[12rem] flex-1">
                                        <label class="label" for="extend_reason">Reason</label>
                                        <input id="extend_reason" name="reason" type="text" maxlength="500" class="input"
                                               placeholder="Late bank transfer, goodwill, …">
                                    </div>
                                    <button type="submit" class="btn btn-secondary">Extend</button>
                                </form>

                                <form method="POST" action="{{ route('admin.businesses.subscription.trial-days', $business) }}"
                                      class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                                    @csrf
                                    <div>
                                        <label class="label" for="trial_extra">Add trial days</label>
                                        <input id="trial_extra" name="days" type="number" min="1" max="365" required
                                               class="input w-28 tabular-nums" placeholder="7">
                                    </div>
                                    <div class="min-w-[12rem] flex-1">
                                        <label class="label" for="trial_reason">Reason</label>
                                        <input id="trial_reason" name="reason" type="text" maxlength="500" class="input"
                                               placeholder="Extra evaluation time">
                                    </div>
                                    <button type="submit" class="btn btn-secondary">Extend trial</button>
                                </form>

                                @if ($subscription->neverExpires())
                                    <p class="text-xs text-amber-600 dark:text-amber-400">
                                        This subscription never expires, so there is nothing to extend.
                                    </p>
                                @endif
                            </div>
                        </details>

                        {{-- cancel / resume --}}
                        <details class="group rounded-xl border border-slate-200 dark:border-slate-800">
                            <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-slate-200">
                                <span class="flex items-center gap-2">
                                    <x-icon name="ban" class="h-4 w-4 text-rose-600 dark:text-rose-400" />
                                    {{ $subscription->isCancelled() ? 'Revert cancellation' : 'Cancel subscription' }}
                                </span>
                                <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open:rotate-180" />
                            </summary>
                            <div class="border-t border-slate-100 p-3 dark:border-slate-800">
                                @if ($subscription->isCancelled())
                                    <form method="POST" action="{{ route('admin.businesses.subscription.resume', $business) }}">
                                        @csrf
                                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">
                                            Clears the cancellation and recomputes the status from the dates — if the
                                            period has already run out it comes back as expired, not active.
                                        </p>
                                        <button type="submit" class="btn btn-primary">Revert cancellation</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.businesses.subscription.cancel', $business) }}"
                                          onsubmit="return confirm('Cancel the subscription for &quot;{{ $business->name }}&quot;? Access stops immediately.');">
                                        @csrf
                                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">
                                            Access stops immediately. The row is kept for history and the cancellation
                                            can be reverted.
                                        </p>
                                        <div class="flex flex-wrap items-end gap-2">
                                            <div class="min-w-[14rem] flex-1">
                                                <label class="label" for="cancel_reason">Reason</label>
                                                <input id="cancel_reason" name="reason" type="text" maxlength="500" class="input">
                                            </div>
                                            <button type="submit" class="btn btn-danger">Cancel subscription</button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        </details>
                    @endif

                    {{-- suspend --}}
                    @if ($business->isActive())
                        <details class="group rounded-xl border border-slate-200 dark:border-slate-800">
                            <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-slate-200">
                                <span class="flex items-center gap-2">
                                    <x-icon name="lock" class="h-4 w-4 text-amber-600 dark:text-amber-400" /> Suspend account
                                </span>
                                <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open:rotate-180" />
                            </summary>
                            <form method="POST" action="{{ route('admin.businesses.suspend', $business) }}"
                                  class="border-t border-slate-100 p-3 dark:border-slate-800"
                                  onsubmit="return confirm('Suspend &quot;{{ $business->name }}&quot;? Its users are signed out on their next request.');">
                                @csrf
                                <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">
                                    Blocks every user of this tenant, independently of the subscription. Already
                                    signed-in sessions are ended on their next request.
                                </p>
                                <div class="flex flex-wrap items-end gap-2">
                                    <div class="min-w-[14rem] flex-1">
                                        <label class="label" for="suspend_reason">Reason</label>
                                        <input id="suspend_reason" name="reason" type="text" maxlength="500" class="input">
                                    </div>
                                    <button type="submit" class="btn btn-danger">Suspend</button>
                                </div>
                            </form>
                        </details>
                    @endif
                </div>
            </div>

            {{-- ------------------------------ payments ------------------------------ --}}
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white">Payments</h3>
                        <p class="mt-0.5 text-xs text-slate-400">
                            Append-only: a mistake is corrected with a refund row, never by editing or deleting one.
                        </p>
                    </div>
                    <span class="badge-slate">{{ $subscription?->amountPaid() !== null ? number_format((float) $subscription->amountPaid(), 2) : '0.00' }} on this period</span>
                </div>

                <div class="mt-4 table-wrap">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead>
                            <tr>
                                <th class="th text-left">Date</th>
                                <th class="th text-right">Amount</th>
                                <th class="th text-left">Method</th>
                                <th class="th text-left">Status</th>
                                <th class="th text-left">Reference</th>
                                <th class="th text-left">Recorded by</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="td whitespace-nowrap text-slate-600 dark:text-slate-300">
                                        {{ $payment->paid_at?->format('d M Y') ?? $payment->created_at?->format('d M Y') }}
                                    </td>
                                    <td class="td text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ $payment->formattedAmount() }}</td>
                                    <td class="td text-slate-600 dark:text-slate-300">{{ $payment->methodLabel() }}</td>
                                    <td class="td"><span class="{{ $payment->status->badgeClass() }}">{{ $payment->status->label() }}</span></td>
                                    <td class="td text-slate-500 dark:text-slate-400">{{ $payment->reference ?: '—' }}</td>
                                    <td class="td text-slate-500 dark:text-slate-400">{{ $payment->recorder?->name ?? 'System' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="td py-6 text-center text-slate-400" colspan="6">No payments recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($subscription !== null)
                    <details class="group mt-4 rounded-xl border border-slate-200 dark:border-slate-800">
                        <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-slate-200">
                            <span class="flex items-center gap-2">
                                <x-icon name="plus" class="h-4 w-4 text-brand-600 dark:text-brand-400" /> Record a payment
                            </span>
                            <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open:rotate-180" />
                        </summary>

                        <form method="POST" action="{{ route('admin.businesses.subscription.payments', $business) }}"
                              class="border-t border-slate-100 p-3 dark:border-slate-800">
                            @csrf
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="sm:col-span-3">
                                    <label class="label" for="pay_subscription">Against period</label>
                                    <select id="pay_subscription" name="subscription_id" required class="input">
                                        @foreach ($history as $row)
                                            <option value="{{ $row->id }}" @selected($row->id === $subscription->id)>
                                                {{ $row->plan?->name ?? 'Deleted plan' }} ·
                                                {{ $row->starts_at?->format('d M Y') }} –
                                                {{ $row->ends_at?->format('d M Y') ?? 'never' }} ·
                                                {{ $row->formattedPrice() }}{{ $row->id === $subscription->id ? ' (current)' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="label" for="pay_amount">Amount</label>
                                    <input id="pay_amount" name="amount" type="number" step="0.01" min="0" required
                                           value="{{ (float) $subscription->price }}" class="input tabular-nums">
                                    @error('amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="label" for="pay_method">Method</label>
                                    <select id="pay_method" name="method" required class="input">
                                        @foreach ($paymentMethods as $method)
                                            <option value="{{ $method }}">{{ Str::headline($method) }}</option>
                                        @endforeach
                                    </select>
                                    @error('method') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="label" for="pay_status">Status</label>
                                    <select id="pay_status" name="status" required class="input">
                                        @foreach (\App\Enums\PaymentStatus::options() as $value => $label)
                                            <option value="{{ $value }}" @selected($value === \App\Enums\PaymentStatus::Paid->value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="label" for="pay_reference">Reference</label>
                                    <input id="pay_reference" name="reference" type="text" maxlength="120" class="input"
                                           placeholder="Transaction / slip no.">
                                </div>

                                <div>
                                    <label class="label" for="pay_paid_at">Received on</label>
                                    <input id="pay_paid_at" name="paid_at" type="date" class="input"
                                           value="{{ now()->format('Y-m-d') }}">
                                </div>

                                <div>
                                    <label class="label" for="pay_notes">Note</label>
                                    <input id="pay_notes" name="notes" type="text" maxlength="500" class="input">
                                </div>
                            </div>

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <p class="text-xs text-slate-400">Recording a payment does not extend the period — renew or extend separately.</p>
                                <button type="submit" class="btn btn-primary">Record payment</button>
                            </div>
                        </form>
                    </details>
                @endif
            </div>

            {{-- ------------------------------- history ------------------------------- --}}
            <div class="card p-5">
                <div class="flex items-center gap-2">
                    <x-icon name="history" class="h-4 w-4 text-slate-400" />
                    <h3 class="font-semibold text-slate-900 dark:text-white">Subscription history</h3>
                </div>

                <ol class="mt-4 space-y-3">
                    @forelse ($history as $row)
                        <li class="flex gap-3">
                            <div class="mt-1.5 flex flex-col items-center">
                                <span @class([
                                    'h-2 w-2 shrink-0 rounded-full',
                                    'bg-brand-500' => $row->isCurrent(),
                                    'bg-slate-300 dark:bg-slate-600' => ! $row->isCurrent(),
                                ])></span>
                                @unless ($loop->last)
                                    <span class="mt-1 w-px flex-1 bg-slate-200 dark:bg-slate-700"></span>
                                @endunless
                            </div>
                            <div class="min-w-0 flex-1 pb-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $row->plan?->name ?? 'Deleted plan' }}</span>
                                    <span class="{{ $row->effectiveStatus()->badgeClass() }}">{{ $row->effectiveStatus()->label() }}</span>
                                    @if ($row->isCurrent())
                                        <span class="badge-brand">Current</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    {{ $row->billing_cycle->label() }} ·
                                    {{ $row->formattedPrice() }} ·
                                    {{ $row->starts_at?->format('d M Y') }} –
                                    {{ $row->ends_at?->format('d M Y') ?? 'never' }}
                                    @if ($row->superseded_at)
                                        · superseded {{ $row->superseded_at->format('d M Y') }}
                                    @endif
                                </p>
                                @if ($row->notes)
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $row->notes }}</p>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="py-4 text-center text-sm text-slate-400">Nothing yet.</li>
                    @endforelse
                </ol>
            </div>

            {{-- -------------------------------- notes -------------------------------- --}}
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="note" class="h-4 w-4 text-slate-400" />
                        <h3 class="font-semibold text-slate-900 dark:text-white">Support notes</h3>
                    </div>
                    <span class="badge-slate">Operator-only</span>
                </div>

                <form method="POST" action="{{ route('admin.businesses.notes.store', $business) }}" class="mt-4">
                    @csrf
                    <label class="label" for="note_body">New note</label>
                    <textarea id="note_body" name="body" rows="2" maxlength="5000" required class="input"
                              placeholder="What happened, what was agreed, who called.">{{ old('body') }}</textarea>
                    @error('body') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                            <input type="hidden" name="is_pinned" value="0">
                            <input type="checkbox" name="is_pinned" value="1"
                                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            Pin to the top
                        </label>
                        <button type="submit" class="btn btn-secondary">Add note</button>
                    </div>
                </form>

                <ul class="mt-4 space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    @forelse ($notes as $note)
                        <li @class([
                            'rounded-xl border p-3',
                            'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' => $note->is_pinned,
                            'border-slate-200 dark:border-slate-800' => ! $note->is_pinned,
                        ])>
                            <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $note->body }}</p>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-slate-400">
                                    {{ $note->authorName() }} ·
                                    {{ $note->created_at?->diffForHumans() }}
                                    @if ($note->is_pinned)
                                        · <span class="font-medium text-amber-600 dark:text-amber-400">pinned</span>
                                    @endif
                                </p>
                                <div class="flex items-center gap-1">
                                    <form method="POST" action="{{ route('admin.businesses.notes.pin', [$business, $note]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost !px-2 !py-1" title="{{ $note->is_pinned ? 'Unpin' : 'Pin' }}">
                                            <x-icon name="pin" class="h-3.5 w-3.5" />
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.businesses.notes.destroy', [$business, $note]) }}"
                                          onsubmit="return confirm('Delete this note?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost !px-2 !py-1 text-rose-600" title="Delete">
                                            <x-icon name="trash" class="h-3.5 w-3.5" />
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="py-2 text-center text-sm text-slate-400">No notes yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- ============================== SIDE COLUMN ============================== --}}
        <div class="space-y-5">

            {{-- -------------------------------- usage -------------------------------- --}}
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Usage vs quota</h3>
                    <a href="{{ route('admin.businesses.overrides.index', $business) }}" class="text-xs text-brand-600 hover:underline dark:text-brand-400">Adjust</a>
                </div>
                <p class="mt-0.5 text-xs text-slate-400">Usage is counted live, never cached.</p>

                <div class="mt-4 space-y-3">
                    @foreach ($meters as $meter)
                        <x-meter :meter="$meter" />
                    @endforeach
                </div>
            </div>

            {{-- ------------------------------ features ------------------------------ --}}
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Effective features</h3>
                    <span class="badge-slate">{{ count(array_filter($featureMap)) }} on</span>
                </div>
                <p class="mt-0.5 text-xs text-slate-400">
                    What this tenant can actually reach: plan, adjusted by any override.
                </p>

                <div class="mt-4 space-y-4">
                    @foreach ($featureGroups as $group => $features)
                        <div>
                            <h4 class="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                {{ $featureGroupLabels[$group] ?? ucfirst($group) }}
                            </h4>
                            <ul class="space-y-1">
                                @foreach ($features as $feature)
                                    @php
                                        $on = (bool) ($featureMap[$feature->code] ?? false);
                                        $override = $featureOverrides[$feature->id] ?? null;
                                    @endphp
                                    <li class="flex items-start gap-2 text-sm">
                                        <x-icon :name="$on ? 'check' : 'x'" @class([
                                            'mt-0.5 h-4 w-4 shrink-0',
                                            'text-emerald-600 dark:text-emerald-400' => $on,
                                            'text-slate-300 dark:text-slate-600' => ! $on,
                                        ]) />
                                        <span class="min-w-0 flex-1">
                                            <span @class([
                                                'block truncate',
                                                'text-slate-700 dark:text-slate-300' => $on,
                                                'text-slate-400' => ! $on,
                                            ])>{{ $feature->name }}</span>
                                            @if ($override)
                                                <span class="block text-[11px] text-brand-600 dark:text-brand-400">
                                                    Overridden {{ $override->is_enabled ? 'on' : 'off' }}@if ($override->reason) — {{ $override->reason }}@endif
                                                </span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- -------------------------------- users -------------------------------- --}}
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Users</h3>
                    <span class="badge-slate">{{ $users->count() }}</span>
                </div>
                <p class="mt-0.5 text-xs text-slate-400">
                    Operators send a reset link — they never see or set a tenant's password.
                </p>

                <ul class="mt-4 space-y-2">
                    @forelse ($users as $user)
                        <li class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $user->name }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ $user->email }}</p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1">
                                    @if ($user->is_business_owner)
                                        <span class="badge-brand">Owner</span>
                                    @endif
                                    @unless ($user->is_active)
                                        <span class="badge-slate">Disabled</span>
                                    @endunless
                                </div>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[11px] text-slate-400">
                                    {{ $user->last_login_at ? 'Last in '.$user->last_login_at->diffForHumans() : 'Never signed in' }}
                                </p>
                                <div class="flex items-center gap-1">
                                    <form method="POST" action="{{ route('admin.businesses.reset-password', $business) }}">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $user->id }}">
                                        <button type="submit" class="btn btn-ghost !px-2 !py-1 text-xs" title="Email a password reset link">
                                            <x-icon name="mail" class="h-3.5 w-3.5" /> Reset link
                                        </button>
                                    </form>
                                    @if ($business->isActive() && $user->is_active)
                                        <form method="POST" action="{{ route('admin.businesses.impersonate', $business) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $user->id }}">
                                            <button type="submit" class="btn btn-ghost !px-2 !py-1 text-xs" title="Sign in as this user">
                                                <x-icon name="user-check" class="h-3.5 w-3.5" /> Sign in
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="py-2 text-center text-sm text-slate-400">No users — this tenant cannot sign in.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

</x-layouts.admin>
