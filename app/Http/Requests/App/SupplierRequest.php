<?php

namespace App\Http\Requests\App;

use App\Models\Supplier;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the supplier form (#38).
 */
class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::SUPPLIERS_MANAGE);
    }

    public function rules(): array
    {
        $supplierId = $this->route('supplier')?->id;
        $businessId = $this->user()->business_id;

        return [
            'name' => ['required', 'string', 'max:180'],

            'code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('suppliers', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($supplierId),
            ],

            'contact_person' => ['nullable', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'tax_number' => ['nullable', 'string', 'max:60'],

            // Blank means "no agreed terms", which is not the same as "due now".
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'opening_balance' => ['nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'opening_balance_date' => ['nullable', 'date', 'before_or_equal:today'],

            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'You already have a supplier with that code.',
            'opening_balance_date.before_or_equal' => 'An opening balance cannot be dated in the future.',
        ];
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();

        if ($this->route('supplier') instanceof Supplier) {
            unset($data['opening_balance'], $data['opening_balance_date']);
        }

        return $data;
    }
}
