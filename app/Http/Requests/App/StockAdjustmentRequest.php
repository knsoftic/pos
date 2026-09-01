<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a manual stock adjustment (#31).
 *
 * The reason is REQUIRED. Every other field here is ordinary validation; that
 * one is a policy decision: a stock figure that changed for no recorded reason
 * is exactly how shrinkage stays invisible, so the system refuses to accept one.
 */
class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::INVENTORY_ADJUST);
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],

            // Signed: +5 found five more, −2 lost two. Zero is refused because it
            // is not a correction, it is a no-op with a reason attached.
            'quantity' => ['required', 'numeric', 'not_in:0', 'min:-9999999', 'max:9999999'],

            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Batch details, for products that track them (#34). Only meaningful
            // when stock is being ADDED — you cannot invent an expiry date for
            // stock that is leaving.
            'batch_number' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:today'],
            'batch_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.not_in' => 'An adjustment of zero would change nothing.',
            'reason.required' => 'Say why the figure is changing — this goes on the record.',
            'product_id.exists' => 'Choose one of your own products.',
            'expiry_date.after_or_equal' => 'An expiry date in the past means the stock is already expired — record it as a loss instead.',
            'branch_id.exists' => 'Choose one of your own branches.',
        ];
    }
}
