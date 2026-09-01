<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for sending goods back to a supplier (#37).
 *
 * The reason is REQUIRED — the same rule as a stock adjustment and a ledger
 * correction. A supplier will query the return, and "no reason recorded" is not
 * an answer.
 */
class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::PURCHASES_RETURN);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'return_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the goods are going back — this goes on the record.',
            'quantities.required' => 'Choose what is going back.',
        ];
    }

    /** @return array<int, float> */
    public function quantities(): array
    {
        $quantities = [];

        foreach ((array) $this->input('quantities', []) as $itemId => $quantity) {
            if ($quantity === null || $quantity === '' || (float) $quantity <= 0) {
                continue;
            }

            $quantities[(int) $itemId] = (float) $quantity;
        }

        return $quantities;
    }
}
