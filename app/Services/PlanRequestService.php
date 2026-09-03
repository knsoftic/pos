<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\PlanRequestStatus;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A shop asking to change plan, and the operator answering (#82).
 *
 * ================= WHAT THIS DELIBERATELY DOES NOT DO =================
 * It does not change anybody's subscription. There is no self-serve checkout in
 * this release, and a button that moved a shop onto a paid plan without money
 * changing hands would be a worse bug than the one it replaced. Filing the
 * request and moving the shop are two separate acts by two different people.
 *
 * ================= ONE OPEN REQUEST AT A TIME =================
 * Asking twice is not two requests, it is somebody who did not hear back. A
 * second ask for the same plan updates the first rather than filling the
 * operator's screen with duplicates of one conversation — and asking for a
 * DIFFERENT plan replaces the older ask, because that is a change of mind, not
 * two shops wanting two things.
 */
class PlanRequestService
{
    public function __construct(
        protected TenantContext $tenant,
        protected SubscriptionService $subscriptions,
        protected AuditService $audit,
    ) {}

    public function open(Plan $plan, ?BillingCycle $cycle = null, ?string $note = null): PlanRequest
    {
        $business = $this->tenant->business();
        $user = Auth::guard('web')->user();
        $current = $this->subscriptions->current($business);

        return DB::transaction(function () use ($plan, $cycle, $note, $user, $current): PlanRequest {
            $request = PlanRequest::query()->pending()->first() ?? new PlanRequest;

            $request->fill([
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle?->value,
                'user_id' => $user?->id,
                // Kept as text so the record still reads correctly after the
                // person leaves the shop or the plan is renamed.
                'requested_by_name' => $user?->name,
                'current_plan_name' => $current?->plan?->name,
                'status' => PlanRequestStatus::Pending,
                'note' => $note,
            ]);

            $request->save();

            $this->audit->log(
                'plan_request.opened',
                $request,
                "Asked to move onto \"{$plan->name}\".",
                ['plan' => $plan->name, 'cycle' => $cycle?->value],
            );

            return $request;
        });
    }

    /** The operator's answer. Does not touch the subscription — see the class note. */
    public function settle(PlanRequest $request, PlanRequestStatus $status, ?Admin $admin = null): PlanRequest
    {
        $request->status = $status;
        $request->handled_by = $admin?->id;
        $request->handled_at = now();
        $request->save();

        return $request;
    }

    /**
     * A wa.me link that opens the shopkeeper's WhatsApp with the message ready.
     *
     * ⚠️ THIS DOES NOT SEND ANYTHING. There is no WhatsApp Business API here, no
     * token and no per-message cost. The link opens WhatsApp ON THE
     * SHOPKEEPER'S OWN DEVICE with the text prefilled and the operator's number
     * in the To field; they still press send. Saying otherwise in the UI would
     * promise a delivery nobody can guarantee — and the request is already
     * filed by then either way, which is what makes this safe to offer.
     *
     * Null when no number is configured, so the button is simply not shown
     * rather than linking somewhere useless.
     */
    public function whatsappLink(PlanRequest $request): ?string
    {
        // Digits only. wa.me rejects a + or a space, and a malformed number
        // opens WhatsApp on an empty chat, which looks like the message was
        // sent and lost.
        $number = preg_replace('/[^0-9]/', '', (string) config('brand.whatsapp', ''));

        if ($number === '' || $number === null) {
            return null;
        }

        $business = $request->business ?? $this->tenant->business();

        $lines = [
            config('brand.name').' — plan request',
            '',
            'Shop: '.$business?->name,
            'Wants: '.$request->plan?->name.($request->billing_cycle ? ' ('.$request->billing_cycle->label().')' : ''),
        ];

        if ($request->current_plan_name) {
            $lines[] = 'Currently on: '.$request->current_plan_name;
        }

        if ($request->requested_by_name) {
            $lines[] = 'Asked by: '.$request->requested_by_name;
        }

        $lines[] = 'Reference: #'.$request->id;

        return 'https://wa.me/'.$number.'?text='.rawurlencode(implode("\n", $lines));
    }
}
