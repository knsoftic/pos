<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\User;
use App\Support\FeatureRegistry;
use App\Support\PermissionRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * What the bell in a shop's workspace shows (#76, #77).
 *
 * ================= TWO KINDS OF THING, ON PURPOSE =================
 * An ALERT is a CONDITION — "six products are below their alert level", "the
 * subscription runs out in four days". It is computed live from the data and it
 * disappears the moment the shop fixes it. It CANNOT be dismissed, because an
 * alert you can swipe away while it is still true is a lie, and a shopkeeper
 * who learns the badge can be silenced without fixing anything learns to
 * silence it.
 *
 * An ANNOUNCEMENT is a MESSAGE from the operator. It does not become false, so
 * a person who has read it must be able to put it away — otherwise the bell
 * fills with things they have already dealt with and they stop opening it.
 *
 * ================= NOTHING IS STORED, AND NOTHING IS CACHED =================
 * There is no notifications table for alerts and no queue delivering them. A
 * stored alert has to be created when a condition starts and deleted when it
 * ends, and the deleting is what nobody ever gets right — so shops end up with
 * a bell full of problems they fixed last month. Computing them per request
 * costs four counting queries and is always true.
 *
 * ⚠️ Each alert is gated on the PERMISSION for the screen it links to. A
 * cashier who cannot open the inventory should not be told what is low in it.
 */
class TenantNotificationService
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    protected const SEVERITY_ORDER = [self::DANGER => 0, self::WARNING => 1, self::INFO => 2];

    /** @var array<int, array<string, mixed>>|null per-request memo, not a cache */
    protected ?array $memo = null;

    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * Everything worth showing, worst first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $user = Auth::guard('web')->user();

        if ($user === null || ! $this->tenant->hasBusiness()) {
            return $this->memo = [];
        }

        $items = array_merge(
            $this->announcements($user),
            $this->subscriptionAlerts($user),
            $this->stockAlerts($user),
        );

        usort($items, fn (array $a, array $b) => [self::SEVERITY_ORDER[$a['level']] ?? 9, $a['title']]
            <=> [self::SEVERITY_ORDER[$b['level']] ?? 9, $b['title']]);

        return $this->memo = $items;
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function hasUrgent(): bool
    {
        return collect($this->all())->contains(fn (array $item) => $item['level'] === self::DANGER);
    }

    /**
     * Put an announcement away for this person.
     *
     * Per PERSON, not per business: the owner having read it does not mean the
     * cashier has.
     */
    public function dismiss(Announcement $announcement, User $user): void
    {
        abort_unless($announcement->is_dismissible, 422, 'That notice cannot be dismissed.');

        DB::table('announcement_dismissals')->updateOrInsert(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['dismissed_at' => now()],
        );

        $this->memo = null;
    }

    // ------------------------------------------------------------- the feeds

    /** @return array<int, array<string, mixed>> */
    protected function announcements(User $user): array
    {
        $dismissed = DB::table('announcement_dismissals')
            ->where('user_id', $user->id)
            ->pluck('announcement_id');

        return Announcement::query()
            ->live()
            ->whereNotIn('id', $dismissed)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Announcement $a) => [
                'key' => 'announcement.'.$a->id,
                'type' => 'announcement',
                'level' => in_array($a->level, [self::DANGER, self::WARNING, self::INFO], true) ? $a->level : self::INFO,
                'title' => $a->title,
                'body' => $a->body,
                'href' => null,
                'dismissible' => $a->is_dismissible,
                'id' => $a->id,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function subscriptionAlerts(User $user): array
    {
        // Only the owner is told about billing. A cashier being warned the shop
        // is about to be cut off is neither their business nor their decision.
        if (! $user->isOwner()) {
            return [];
        }

        $subscription = $this->subscriptions->current($this->tenant->business());

        if ($subscription === null) {
            return [[
                'key' => 'subscription.none',
                'type' => 'alert',
                'level' => self::DANGER,
                'title' => 'No active subscription',
                'body' => 'This shop has no plan. Choose one to keep working.',
                'href' => route('app.billing.index'),
                'dismissible' => false,
            ]];
        }

        $daysLeft = $subscription->ends_at === null
            ? null
            : (int) now()->startOfDay()->diffInDays($subscription->ends_at->startOfDay(), false);

        if ($daysLeft === null) {
            return [];
        }

        if ($daysLeft < 0) {
            return [[
                'key' => 'subscription.expired',
                'type' => 'alert',
                'level' => self::DANGER,
                'title' => 'Subscription expired',
                'body' => 'It ran out '.abs($daysLeft).' '.str('day')->plural(abs($daysLeft)).' ago.',
                'href' => route('app.billing.index'),
                'dismissible' => false,
            ]];
        }

        // Warn on the days the operator configured, not every day — a warning
        // that appears every morning for a month is wallpaper.
        $warnOn = (array) config('subscription.warning_days', [7, 3, 1]);

        if (! in_array($daysLeft, array_map('intval', $warnOn), true)) {
            return [];
        }

        return [[
            'key' => 'subscription.expiring',
            'type' => 'alert',
            'level' => $daysLeft <= 3 ? self::DANGER : self::WARNING,
            'title' => 'Subscription ends in '.$daysLeft.' '.str('day')->plural($daysLeft),
            'body' => 'Renew before it runs out to avoid interruption.',
            'href' => route('app.billing.index'),
            'dismissible' => false,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    protected function stockAlerts(User $user): array
    {
        if (! $user->can(PermissionRegistry::INVENTORY_VIEW)) {
            return [];
        }

        $alerts = [];

        if ($this->features->enabled(FeatureRegistry::INVENTORY_STOCK_TRACKING)) {
            $low = Stock::query()->lowStock()->count();

            if ($low > 0) {
                $alerts[] = [
                    'key' => 'stock.low',
                    'type' => 'alert',
                    'level' => self::WARNING,
                    'title' => $low.' '.str('shelf')->plural($low).' at or below the alert level',
                    'body' => 'Reorder before they run out.',
                    'href' => route('app.reports.show', 'inventory.low_stock'),
                    'dismissible' => false,
                ];
            }
        }

        if ($this->features->enabled(FeatureRegistry::INVENTORY_EXPIRY_TRACKING)) {
            $days = (int) config('inventory.expiry_warning_days', 30);

            $expiring = StockBatch::query()
                ->whereNotNull('expiry_date')
                ->where('quantity', '>', 0)
                ->where('expiry_date', '<=', now()->addDays($days)->toDateString())
                ->count();

            if ($expiring > 0) {
                $expired = StockBatch::query()
                    ->whereNotNull('expiry_date')
                    ->where('quantity', '>', 0)
                    ->where('expiry_date', '<', now()->toDateString())
                    ->count();

                $alerts[] = [
                    'key' => 'stock.expiry',
                    'type' => 'alert',
                    // Already expired is a different problem from expiring soon:
                    // one is a decision, the other is stock that must not sell.
                    'level' => $expired > 0 ? self::DANGER : self::WARNING,
                    'title' => $expired > 0
                        ? $expired.' '.str('batch')->plural($expired).' already expired'
                        : $expiring.' '.str('batch')->plural($expiring).' expiring soon',
                    'body' => $expired > 0
                        ? 'Take them off the shelf — they must not be sold.'
                        : 'Within the next '.$days.' days.',
                    'href' => route('app.reports.show', 'inventory.expiry'),
                    'dismissible' => false,
                ];
            }
        }

        return $alerts;
    }
}
