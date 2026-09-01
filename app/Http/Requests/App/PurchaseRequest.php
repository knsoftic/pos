<?php

namespace App\Http\Requests\App;

use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the purchase form (#35).
 *
 * Line existence and tenancy are re-checked by {@see PurchaseService}
 * — this only has to keep obvious nonsense out and drop the blank template row
 * the form always renders.
 */
class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can($this->purchase() === null
            ? PermissionRegistry::PURCHASES_CREATE
            : PermissionRegistry::PURCHASES_UPDATE);
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'branch_id' => [
                'required', 'integer',
                Rule::exists('branches', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],

            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],

            // Backdating is normal — paperwork catches up with deliveries.
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            // An expected date, by contrast, is in the future by definition.
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],

            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity_ordered' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'A purchase needs at least one line.',
            'supplier_id.exists' => 'Choose one of your own suppliers.',
            'branch_id.exists' => 'Choose one of your own branches.',
            'order_date.before_or_equal' => 'An order cannot be dated in the future.',
            'expected_date.after_or_equal' => 'The expected date cannot be before the order date.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->lineRows() === []) {
                $validator->errors()->add('lines', 'A purchase needs at least one line with a product and a quantity.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function purchaseAttributes(): array
    {
        return [
            'supplier_id' => $this->input('supplier_id'),
            'branch_id' => $this->input('branch_id'),
            'supplier_invoice_no' => $this->input('supplier_invoice_no') ?: null,
            'order_date' => $this->input('order_date'),
            'expected_date' => $this->input('expected_date') ?: null,
            'notes' => $this->input('notes') ?: null,
        ];
    }

    /**
     * The lines, cleaned of the blank row the form always renders.
     *
     * @return list<array<string, mixed>>
     */
    public function lineRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('lines', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            // A row with no product, or nothing on it, is the empty template.
            if (blank($row['product_id'] ?? null) || (float) ($row['quantity_ordered'] ?? 0) <= 0) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function purchase(): ?Purchase
    {
        $purchase = $this->route('purchase');

        return $purchase instanceof Purchase ? $purchase : null;
    }
}
