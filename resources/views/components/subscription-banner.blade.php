@props([
    'subscription' => null,
    'readOnly' => false,
    'expired' => false,
])

@php
    use App\Enums\ExpiryBehavior;

    /*
     | The expiry warning strip (#11).
     |
     | One component, every state, because the states are mutually exclusive and
     | scattering them across pages guarantees they drift. Order matters: the most
     | severe true statement wins, so a cancelled subscription is never described
     | as "expiring soon".
     |
     | What happens after grace is operator policy, not a hardcoded sentence
     | (#190) — the copy is read from ExpiryBehavior so changing the config
     | changes what the tenant is told.
     */
    $behavior = ExpiryBehavior::fromConfig();
    $days = $subscription?->daysRemaining();
    $threshold = $subscription?->expiryWarningThreshold();
    $graceLeft = $subscription?->graceDaysRemaining();

    $banner = null;

    if ($subscription === null) {
        $banner = [
            'tone' => 'rose',
            'icon' => 'ban',
            'title' => 'No active subscription',
            'body' => 'Your account is not on a plan, so the app is locked. Contact us to get set up.',
        ];
    } elseif ($subscription->isCancelled()) {
        $banner = [
            'tone' => 'rose',
            'icon' => 'ban',
            'title' => 'Your subscription was cancelled',
            'body' => 'Access has stopped. Get in touch and we can reactivate it — nothing has been deleted.',
        ];
    } elseif ($readOnly || $expired || $subscription->isExpired()) {
        $banner = [
            'tone' => 'rose',
            'icon' => 'alert',
            'title' => 'Your subscription has expired',
            'body' => $behavior->description(),
        ];
    } elseif ($subscription->isInGrace()) {
        $banner = [
            'tone' => 'amber',
            'icon' => 'clock',
            'title' => $graceLeft === 0
                ? 'Your grace period ends today'
                : 'You are in a '.$graceLeft.'-day grace period',
            'body' => 'The plan ran out on '.$subscription->ends_at?->format('d M Y').'. Renew now to avoid interruption.',
        ];
    } elseif ($subscription->isOnTrial()) {
        $trialLeft = $subscription->trial_ends_at !== null
            ? max(0, (int) now()->startOfDay()->diffInDays($subscription->trial_ends_at->copy()->startOfDay(), false))
            : null;

        $banner = [
            'tone' => 'brand',
            'icon' => 'zap',
            'title' => match (true) {
                $trialLeft === null => 'You are on a free trial',
                $trialLeft === 0 => 'Your trial ends today',
                default => 'Your trial ends in '.$trialLeft.' day'.($trialLeft === 1 ? '' : 's'),
            },
            'body' => 'Pick a plan before it runs out and you keep everything you have entered.',
        ];
    } elseif ($threshold !== null && $days !== null) {
        $banner = [
            'tone' => 'amber',
            'icon' => 'clock',
            'title' => $days === 0
                ? 'Your subscription expires today'
                : 'Your subscription expires in '.$days.' day'.($days === 1 ? '' : 's'),
            'body' => 'Renew before '.$subscription->ends_at?->format('d M Y').' to keep working without interruption.',
        ];
    }

    $tones = [
        'rose' => 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
        'brand' => 'border-brand-200 bg-brand-50 text-brand-900 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-200',
    ];
@endphp

@if ($banner !== null)
    <div class="border-b px-4 py-3 sm:px-6 {{ $tones[$banner['tone']] }}">
        <div class="flex flex-wrap items-start gap-3">
            <x-icon :name="$banner['icon']" class="mt-0.5 h-5 w-5 shrink-0" />

            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold">{{ $banner['title'] }}</p>
                <p class="mt-0.5 opacity-90">{{ $banner['body'] }}</p>
            </div>

            @unless (request()->routeIs('app.billing.*'))
                <a href="{{ route('app.billing.index') }}"
                   class="shrink-0 rounded-xl border border-current/20 bg-white/70 px-3 py-1.5 text-sm font-semibold hover:bg-white dark:bg-white/10 dark:hover:bg-white/20">
                    View billing
                </a>
            @endunless
        </div>
    </div>
@endif
