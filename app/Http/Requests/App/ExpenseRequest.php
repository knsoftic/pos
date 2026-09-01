<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for money spent (#43).
 *
 * The receipt file is checked by CONTENT, not by the name it arrived with, and
 * the allowed list lives in `config/uploads.php` so it cannot drift between the
 * expense form and the income form (#101, #190).
 *
 * A future-dated expense is refused: booking next month's rent into this month
 * would move profit between periods, and the shop would find its accounts
 * disagreeing with its bank for reasons nobody could reconstruct.
 */
class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::EXPENSES_MANAGE);
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;
        $receipts = config('uploads.receipts');

        return [
            'expense_category_id' => [
                'required', 'integer',
                Rule::exists('expense_categories', 'id')
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at'),
            ],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],

            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_method' => ['nullable', Rule::in(config('pos.payment_methods', []))],

            'payee' => ['nullable', 'string', 'max:255'],
            'bill_no' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],

            'attachment' => ['nullable', 'file', 'mimes:'.implode(',', $receipts['mimes']), 'max:'.$receipts['max_kb']],
            'remove_attachment' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'An amount of zero is not a record of anything.',
            'expense_date.before_or_equal' => 'An expense cannot be dated in the future.',
            'expense_category_id.required' => 'Choose which heading this belongs under.',
            'attachment.mimes' => 'A receipt has to be an image or a PDF.',
        ];
    }

    /** @return array<string, mixed> */
    public function expenseAttributes(): array
    {
        return [
            'expense_category_id' => (int) $this->input('expense_category_id'),
            'branch_id' => $this->input('branch_id') ?: null,
            'expense_date' => $this->input('expense_date'),
            'amount' => (float) $this->input('amount'),
            'payment_method' => $this->input('payment_method') ?: 'cash',
            'payee' => $this->input('payee') ?: null,
            'bill_no' => $this->input('bill_no') ?: null,
            'note' => $this->input('note') ?: null,
            'attachment' => $this->file('attachment'),
            'remove_attachment' => $this->boolean('remove_attachment'),
        ];
    }
}
