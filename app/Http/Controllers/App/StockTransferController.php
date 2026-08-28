<?php

namespace App\Http\Controllers\App;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StockTransferRequest;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Stock transfers between branches (#32).
 *
 * Visibility is by BOTH ends: a manager needs to see what is coming to their
 * shop as much as what they sent, so the list is scoped with
 * {@see StockTransfer::scopeVisibleTo()} rather than a single branch filter
 * (#48). Whether they may act on it is decided by the service, per action —
 * sending is the source's job, receiving is the destination's.
 */
class StockTransferController extends Controller
{
    public function __construct(
        protected StockTransferService $transfers,
        protected BranchContext $branches,
    ) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');

        $transfers = StockTransfer::query()
            ->with(['fromBranch:id,name', 'toBranch:id,name', 'items'])
            ->visibleTo($this->branches->branchIds())
            ->when($status !== '', fn (Builder $q) => $q->status($status))
            ->newestFirst()
            ->paginate(20)
            ->withQueryString();

        return view('app.transfers.index', [
            'transfers' => $transfers,
            'status' => $status,
            'statuses' => TransferStatus::options(),
            // The two figures a branch manager actually wants at a glance.
            'incoming' => StockTransfer::query()
                ->visibleTo($this->branches->branchIds())
                ->status(TransferStatus::Sent)
                ->count(),
            'drafts' => StockTransfer::query()
                ->visibleTo($this->branches->branchIds())
                ->status(TransferStatus::Draft)
                ->count(),
        ]);
    }

    public function create(): View
    {
        return view('app.transfers.create', $this->formData(new StockTransfer));
    }

    public function store(StockTransferRequest $request): RedirectResponse
    {
        $transfer = $this->transfers->create($request->transferAttributes(), $request->itemRows());

        return redirect()
            ->route('app.transfers.show', $transfer)
            ->with('success', "Transfer {$transfer->reference} drafted. Send it when the goods are packed.");
    }

    public function show(StockTransfer $transfer): View
    {
        $transfer->load(['items.product:id,name,sku,unit_id', 'items.product.unit:id,short_name', 'items.variant:id,name',
            'fromBranch:id,name', 'toBranch:id,name', 'creator:id,name', 'sender:id,name', 'receiver:id,name']);

        return view('app.transfers.show', [
            'transfer' => $transfer,
            'canSend' => $transfer->status->canBeSent() && $this->branches->allows($transfer->from_branch_id),
            'canReceive' => $transfer->status->canBeReceived() && $this->branches->allows($transfer->to_branch_id),
            'canCancel' => $transfer->status->canBeCancelled() && (
                $transfer->status->isInTransit()
                    ? $this->branches->allows($transfer->from_branch_id)
                    : $this->branches->allows($transfer->from_branch_id) || $this->branches->allows($transfer->to_branch_id)
            ),
        ]);
    }

    public function edit(StockTransfer $transfer): View
    {
        abort_unless($transfer->status->isEditable(), 422, 'Only a draft can be edited.');

        $transfer->load('items');

        return view('app.transfers.edit', $this->formData($transfer));
    }

    public function update(StockTransferRequest $request, StockTransfer $transfer): RedirectResponse
    {
        $this->transfers->update($transfer, $request->transferAttributes(), $request->itemRows());

        return redirect()
            ->route('app.transfers.show', $transfer)
            ->with('success', 'Transfer updated.');
    }

    /** The goods leave the source shelf (#32). */
    public function send(StockTransfer $transfer): RedirectResponse
    {
        $this->transfers->send($transfer);

        return back()->with('success', "Transfer {$transfer->reference} is on its way.");
    }

    /**
     * The destination counts what arrived. Anything short of what was sent
     * stays short — see the note in StockTransferService.
     */
    public function receive(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $counted = [];

        foreach ((array) $request->input('received', []) as $itemId => $quantity) {
            if ($quantity !== null && $quantity !== '') {
                $counted[(int) $itemId] = (float) $quantity;
            }
        }

        $this->transfers->receive($transfer, $counted);

        $transfer->refresh()->load('items');

        return back()->with(
            $transfer->hasShortfall() ? 'error' : 'success',
            $transfer->hasShortfall()
                ? "Received with a shortfall of {$transfer->shortfall()} — the difference left the source and never arrived."
                : "Transfer {$transfer->reference} received in full.",
        );
    }

    public function cancel(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->transfers->cancel($transfer, $validated['reason']);

        return back()->with('success', "Transfer {$transfer->reference} cancelled.");
    }

    public function destroy(StockTransfer $transfer): RedirectResponse
    {
        if (! $this->transfers->delete($transfer)) {
            return back()->with('error', 'That transfer has already moved stock — cancel it instead.');
        }

        return redirect()
            ->route('app.transfers.index')
            ->with('success', 'Draft transfer deleted.');
    }

    /** @return array<string, mixed> */
    protected function formData(StockTransfer $transfer): array
    {
        return [
            'transfer' => $transfer,
            // Source must be a branch this user can reach; destination can be
            // any branch in the business — you send TO places you do not work at.
            'sourceBranches' => Branch::query()->accessible()->active()->ordered()->get(['id', 'name']),
            'branches' => Branch::query()->active()->ordered()->get(['id', 'name']),
            'products' => Product::query()
                ->active()
                ->stocked()
                ->with('variants:id,product_id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'type']),
        ];
    }
}
