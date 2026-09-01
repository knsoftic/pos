<?php

namespace App\Http\Requests\App;

use App\Models\Supplier;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for money moving on a party account (#41, #42).
 *
 * Shared by both sides because the fields are the same; only the permission
 * differs, and that is decided by which party the route carries.
 */
class LedgerPaymentRequest extends FormRequest
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
            // A payment is always positive: taking a negative payment is a
            // refund, which is a different entry with a different name.
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],

            'payment_method' => ['nullable', Rule::in(config('subscription.payment_methods', []))],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],

            // Backdating is allowed — a shop entering Friday's takings on Monday
            // needs the statement to read Friday — but not forward-dating.
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'A payment must be more than zero.',
            'entry_date.before_or_equal' => 'A payment cannot be dated in the future.',
        ];
    }

    protected function isSupplier(): bool
    {
        return $this->route('supplier') instanceof Supplier;
    }
}
