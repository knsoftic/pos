<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\PurchaseReturnRequest;
use App\Models\Purchase;
use App\Services\PurchaseReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Sending goods back to a supplier (#37).
 *
 * Separate from {@see PurchaseController} because it is a separate authority:
 * receiving a delivery and sending one back are different decisions, and #140's
 * reasoning for sales returns applies just as well here.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(protected PurchaseReturnService $returns) {}

    public function create(Purchase $purchase): View
    {
        abort_unless(
            $purchase->status->hasPosted(),
            422,
            'Nothing has arrived on this purchase, so nothing can go back.',
        );

        $purchase->load(['items.product:id,name', 'items.variant:id,name', 'items.returnItems', 'supplier']);

        return view('app.purchases.return', ['purchase' => $purchase]);
    }

    public function store(PurchaseReturnRequest $request, Purchase $purchase): RedirectResponse
    {
        $return = $this->returns->create($purchase, [
            'reason' => $request->string('reason')->toString(),
            'return_date' => $request->input('return_date'),
            'notes' => $request->input('notes'),
        ], $request->quantities());

        return redirect()
            ->route('app.purchases.show', $purchase)
            ->with('success', sprintf(
                'Return %s recorded — %s credited to %s.',
                $return->reference,
                number_format((float) $return->total, 2),
                $purchase->supplier?->name,
            ));
    }
}
