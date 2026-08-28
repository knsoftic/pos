<?php

namespace App\Http\Requests\App;

use App\Services\StockTransferService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the transfer form (#32).
 *
 * Branch ownership is checked here (they must be this tenant's branches) but
 * branch ACCESS is not — that is {@see StockTransferService}'s
 * job, because the answer differs per action: you may send only from a branch
 * you can reach, but you may send to any branch in the business.
 */
class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::INVENTORY_TRANSFER);
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        $branchExists = fn () => Rule::exists('branches', 'id')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at');

        return [
            'from_branch_id' => ['required', 'integer', $branchExists()],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id', $branchExists()],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'items.*.variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_branch_id.different' => 'A transfer needs two different branches.',
            'items.required' => 'Add at least one product to transfer.',
            'items.*.quantity.gt' => 'Every line needs a quantity greater than zero.',
        ];
    }

    /** @return array<string, mixed> */
    public function transferAttributes(): array
    {
        return [
            'from_branch_id' => (int) $this->input('from_branch_id'),
            'to_branch_id' => (int) $this->input('to_branch_id'),
            'notes' => $this->input('notes') ?: null,
        ];
    }

    /**
     * Lines, minus the blank template row the form always renders.
     *
     * @return list<array<string, mixed>>
     */
    public function itemRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('items', []) as $row) {
            if (! is_array($row) || blank($row['product_id'] ?? null)) {
                continue;
            }

            $rows[] = [
                'product_id' => (int) $row['product_id'],
                'variant_id' => blank($row['variant_id'] ?? null) ? null : (int) $row['variant_id'],
                'quantity' => (float) ($row['quantity'] ?? 0),
                'notes' => $row['notes'] ?? null,
            ];
        }

        return $rows;
    }
}
