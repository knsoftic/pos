<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for money in that was not a sale (#44).
 *
 * `source` is REQUIRED. "Other income" with no stated source is the line every
 * auditor asks about and nobody can answer six months later; making it optional
 * would guarantee a column of blanks.
 *
 * Shares the `expenses.manage` permission deliberately: recording what came in
 * and what went out is one bookkeeping job, done by the same person, and a
 * second permission would only ever be granted alongside the first.
 */
class OtherIncomeRequest extends FormRequest
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
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],

            'income_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_method' => ['nullable', Rule::in(config('pos.payment_methods', []))],

            'source' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],

            'attachment' => ['nullable', 'file', 'mimes:'.implode(',', $receipts['mimes']), 'max:'.$receipts['max_kb']],
            'remove_attachment' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'An amount of zero is not a record of anything.',
            'income_date.before_or_equal' => 'Income cannot be dated in the future.',
            'source.required' => 'Say where the money came from — this is the line people ask about.',
        ];
    }

    /** @return array<string, mixed> */
    public function incomeAttributes(): array
    {
        return [
            'branch_id' => $this->input('branch_id') ?: null,
            'income_date' => $this->input('income_date'),
            'amount' => (float) $this->input('amount'),
            'payment_method' => $this->input('payment_method') ?: 'cash',
            'source' => $this->string('source')->toString(),
            'note' => $this->input('note') ?: null,
            'attachment' => $this->file('attachment'),
            'remove_attachment' => $this->boolean('remove_attachment'),
        ];
    }
}
