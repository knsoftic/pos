<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for goods coming back (#53, #140).
 *
 * `sales.return` is its own permission, deliberately (#140): taking money back
 * out of the till is not the same authority as putting it in, and plenty of
 * shops let anyone sell while only a supervisor may refund.
 *
 * The reason is REQUIRED, like every other reversal in this codebase. It also
 * earns its keep operationally: it is usually what tells the next person whether
 * the goods can go back on the shelf.
 */
class SaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::SALES_RETURN);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'return_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.restock' => ['boolean'],
            'lines.*.condition_note' => ['nullable', 'string', 'max:255'],

            // How the money goes back. Both optional: left alone, the service
            // credits an account customer and refunds a walk-in, which is what
            // each of them usually wants.
            'refund_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'credit_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'refund_method' => ['nullable', Rule::in(config('pos.payment_methods', []))],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the goods are coming back — this goes on the record.',
            'lines.required' => 'Choose what is coming back.',
            'return_date.before_or_equal' => 'A return cannot be dated in the future.',
        ];
    }

    /**
     * Lines keyed by sale item id, with the blank rows dropped.
     *
     * @return array<int, array{quantity: float, restock: bool, condition_note: string|null}>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ((array) $this->input('lines', []) as $itemId => $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = (float) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $lines[(int) $itemId] = [
                'quantity' => $quantity,
                // Unticked means the goods are not fit to sell again, and that
                // is a real answer rather than a missing one.
                'restock' => (bool) ($line['restock'] ?? false),
                'condition_note' => $line['condition_note'] ?? null,
            ];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function returnAttributes(): array
    {
        $data = [
            'reason' => $this->string('reason')->toString(),
            'return_date' => $this->input('return_date'),
            'notes' => $this->input('notes') ?: null,
            'refund_method' => $this->input('refund_method') ?: 'cash',
        ];

        // Only pass a figure the user actually gave: an absent key means "you
        // decide", which is not the same as zero.
        if ($this->filled('refund_amount')) {
            $data['refund_amount'] = (float) $this->input('refund_amount');
        }

        if ($this->filled('credit_amount')) {
            $data['credit_amount'] = (float) $this->input('credit_amount');
        }

        return $data;
    }
}
