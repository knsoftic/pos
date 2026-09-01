<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\LedgerAdjustmentRequest;
use App\Http\Requests\App\LedgerPaymentRequest;
use App\Models\Customer;
use App\Services\CustomerLedgerService;
use Illuminate\Http\RedirectResponse;

/**
 * Money moving on a customer's account (#41).
 *
 * Separate from {@see CustomerController} because it is a different authority:
 * `customers.manage` edits a name and address, `customers.ledger` moves what
 * someone owes. #52 calls the second one sensitive, and the routes gate them
 * accordingly.
 */
class CustomerLedgerController extends Controller
{
    public function __construct(protected CustomerLedgerService $ledger) {}

    /** Take money against what the customer owes. */
    public function payment(LedgerPaymentRequest $request, Customer $customer): RedirectResponse
    {
        $entry = $this->ledger->recordPayment($customer, (float) $request->input('amount'), [
            'entry_date' => $request->input('entry_date'),
            'payment_method' => $request->input('payment_method'),
            'reference_no' => $request->input('reference_no'),
            'description' => $request->input('description') ?: 'Payment received',
        ]);

        return back()->with('success', sprintf(
            'Received %s. %s',
            number_format($entry->amount(), 2),
            $this->balanceSentence($customer),
        ));
    }

    /**
     * A correction, with a reason on the record.
     *
     * Positive adds to what they owe, negative reduces it — the same convention
     * as a stock adjustment, so the two screens read the same way.
     */
    public function adjustment(LedgerAdjustmentRequest $request, Customer $customer): RedirectResponse
    {
        $entry = $this->ledger->adjust(
            $customer,
            (float) $request->input('amount'),
            $request->string('reason')->toString(),
            $request->input('entry_date'),
        );

        return back()->with('success', sprintf(
            'Account adjusted by %s. %s',
            number_format($entry->amount(), 2),
            $this->balanceSentence($customer),
        ));
    }

    /** One plain sentence for the flash message, whichever way the balance sits. */
    protected function balanceSentence(Customer $customer): string
    {
        $customer->refresh();

        return match ($customer->balanceDirection()) {
            'settled' => 'The account is now settled.',
            'owing' => 'They now owe '.number_format($customer->owesUs(), 2).'.',
            default => 'They are now '.number_format($customer->inCredit(), 2).' in credit.',
        };
    }
}
