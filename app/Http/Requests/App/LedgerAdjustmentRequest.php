<?php

namespace App\Http\Requests\App;

use App\Models\Supplier;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a manual correction to a party's balance (#41, #42).
 *
 * The reason is REQUIRED, for the same reason it is on a stock adjustment: a
 * change to what someone owes, with nothing recorded about why, is the first
 * entry an auditor asks about.
 */
class LedgerAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can($this->isSupplier()
            ? PermissionRegistry::SUPPLIERS_LEDGER
            : PermissionRegistry::CUSTOMERS_LEDGER);
    }

    public function rules(): array
    {
        return [
            // Signed: positive increases what the account owes, negative reduces
            // it — the same convention as a stock adjustment, so the two screens
            // read the same way. Zero is refused: it is a no-op with a reason.
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-999999999', 'max:999999999'],
            'reason' => ['required', 'string', 'max:255'],
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.not_in' => 'An adjustment of zero would change nothing.',
            'reason.required' => 'Say why the balance is changing — this goes on the record.',
        ];
    }

    protected function isSupplier(): bool
    {
        return $this->route('supplier') instanceof Supplier;
    }
}
