<?php

namespace App\Services;

use App\Enums\CashSessionStatus;
use App\Exceptions\FeatureUnavailableException;
use App\Models\CashSession;
use App\Models\SalePayment;
use App\Support\BranchContext;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Opening and closing a till (#46, #139).
 *
 * ONE OPEN SESSION PER COUNTER, enforced here rather than by the database:
 * MySQL has no partial unique index, so "at most one row with status = open per
 * counter" cannot be a constraint. The check therefore runs inside a
 * transaction that locks the counter's existing sessions first, which is what
 * stops two people opening the same drawer at the same moment.
 *
 * The running cash figures are maintained as sales land (see
 * {@see SaleService}), so closing a till never has to sum a day of payments.
 * {@see recalculate()} rebuilds them from the payments themselves — the same
 * repair-and-proof pattern the stock and party ledgers use.
 */
class CashSessionService
{
    public function __construct(
        protected TenantContext $tenant,
        protected BranchContext $branches,
        protected FeatureService $features,
        protected AuditService $audit,
    ) {}

    /**
     * Open the drawer and count the float in.
     *
     * @param  array{branch_id?: int|null, pos_counter_id?: int|null, opening_float?: float, notes?: string|null}  $data
     */
    public function open(array $data = []): CashSession
    {
        $this->assertFeature();

        $user = Auth::guard('web')->user();
        $branchId = (int) ($data['branch_id'] ?? $user?->branch_id ?? 0);

        abort_if($branchId === 0, 422, 'A cash session needs a branch.');
        abort_unless($this->branches->allows($branchId), 403, 'That branch is outside your access.');

        $counterId = $data['pos_counter_id'] ?? $user?->pos_counter_id;

        return DB::transaction(function () use ($data, $branchId, $counterId, $user): CashSession {
            // Lock this counter's sessions before deciding whether one is
            // already open — see the class docblock.
            $existing = CashSession::query()
                ->allBranches()
                ->where('branch_id', $branchId)
                ->when($counterId !== null, fn ($q) => $q->where('pos_counter_id', $counterId))
                ->where('status', CashSessionStatus::Open)
                ->lockForUpdate()
                ->first();

            abort_if(
                $existing !== null,
                422,
                'That till already has an open session. Close it before opening another.',
            );

            $session = new CashSession([
                'branch_id' => $branchId,
                'pos_counter_id' => $counterId,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'status' => CashSessionStatus::Open,
                'opened_at' => now(),
                'opening_float' => round(max(0, (float) ($data['opening_float'] ?? 0)), 2),
                'opening_notes' => $data['notes'] ?? null,
            ]);
            $session->save();

            $this->audit->log(
                'cash_session.opened',
                $session,
                sprintf('Till opened with %s float.', number_format((float) $session->opening_float, 2)),
                ['branch_id' => $branchId, 'counter_id' => $counterId],
            );

            return $session;
        });
    }

    /**
     * Count the drawer and close it.
     *
     * The difference is STAMPED, not derived on read: a cash-up is a statement
     * about a moment, and voiding a sale next week must not silently rewrite
     * last week's shortfall.
     */
    public function close(CashSession $session, float $countedCash, ?string $notes = null): CashSession
    {
        abort_unless($session->isOpen(), 422, 'That session is already closed.');
        abort_unless($this->branches->allows($session->branch_id), 403, 'That branch is outside your access.');

        return DB::transaction(function () use ($session, $countedCash, $notes): CashSession {
            $expected = $session->expectedCash();
            $counted = round($countedCash, 2);

            $session->status = CashSessionStatus::Closed;
            $session->closed_at = now();
            $session->expected_cash = $expected;
            $session->counted_cash = $counted;
            $session->difference = round($counted - $expected, 2);
            $session->closing_notes = $notes;
            $session->save();

            $this->audit->log(
                'cash_session.closed',
                $session,
                sprintf(
                    'Till closed: %s expected, %s counted (%s).',
                    number_format($expected, 2),
                    number_format($counted, 2),
                    $session->differenceLabel(),
                ),
                [
                    'expected' => $expected,
                    'counted' => $counted,
                    'difference' => (float) $session->difference,
                    'sales' => $session->sales()->counted()->count(),
                ],
            );

            return $session;
        });
    }

    /**
     * Money in or out of the drawer that is not a sale — a float top-up, paying
     * the window cleaner (#46).
     */
    public function recordMovement(CashSession $session, float $amount, string $reason, bool $isIn): CashSession
    {
        abort_unless($session->isOpen(), 422, 'That session is closed.');
        abort_if($amount <= 0, 422, 'The amount must be more than zero.');

        return DB::transaction(function () use ($session, $amount, $reason, $isIn): CashSession {
            $locked = CashSession::query()->allBranches()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($isIn) {
                $locked->cash_in = round((float) $locked->cash_in + $amount, 2);
            } else {
                $locked->cash_out = round((float) $locked->cash_out + $amount, 2);
            }

            $locked->save();

            $this->audit->log(
                $isIn ? 'cash_session.paid_in' : 'cash_session.paid_out',
                $locked,
                sprintf('%s %s: %s', $isIn ? 'Paid in' : 'Paid out', number_format($amount, 2), $reason),
                ['amount' => $amount, 'reason' => $reason],
            );

            return $locked;
        });
    }

    /** The session a till should be posting into, if any. */
    public function currentFor(?int $branchId = null, ?int $counterId = null): ?CashSession
    {
        $user = Auth::guard('web')->user();

        $branchId ??= $user?->branch_id;
        $counterId ??= $user?->pos_counter_id;

        if ($branchId === null) {
            return null;
        }

        return CashSession::query()
            ->allBranches()
            ->where('branch_id', $branchId)
            ->when($counterId !== null, fn ($q) => $q->where('pos_counter_id', $counterId))
            ->where('status', CashSessionStatus::Open)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Rebuild the running figures from the payments that actually landed.
     *
     * Both the repair tool and the proof: if this changes a number, the cache
     * had drifted from what the sales say.
     *
     * @return array{before: float, after: float, drifted: bool}
     */
    public function recalculate(CashSession $session): array
    {
        return DB::transaction(function () use ($session): array {
            $locked = CashSession::query()->allBranches()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            $before = (float) $locked->cash_sales;

            $after = round((float) SalePayment::query()
                ->cash()
                ->whereHas('sale', fn ($q) => $q
                    ->where('cash_session_id', $locked->id)
                    ->counted())
                ->sum('amount'), 2);

            $locked->cash_sales = $after;
            $locked->save();

            return [
                'before' => $before,
                'after' => $after,
                'drifted' => abs($before - $after) > 0.005,
            ];
        });
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::ACCOUNTING_CASH_REGISTER)) {
            throw new FeatureUnavailableException(
                FeatureRegistry::ACCOUNTING_CASH_REGISTER,
                'Cash register',
            );
        }
    }
}
