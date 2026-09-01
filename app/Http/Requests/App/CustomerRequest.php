<?php

namespace App\Http\Requests\App;

use App\Models\Customer;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the customer form (#39, #40).
 *
 * The credit fields are the interesting part. `unlimited_credit` is a separate
 * checkbox rather than "leave the box empty for unlimited", because an empty
 * field is exactly what someone types when they mean "none" — and quietly
 * reading that as "no ceiling" is how a shop discovers it has extended infinite
 * credit to a walk-in.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::CUSTOMERS_MANAGE);
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;
        $businessId = $this->user()->business_id;

        return [
            'name' => ['required', 'string', 'max:180'],

            'code' => [
                'nullable', 'string', 'max:30',
                Rule::unique('customers', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($customerId),
            ],

            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'tax_number' => ['nullable', 'string', 'max:60'],

            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'unlimited_credit' => ['boolean'],

            // Only meaningful when creating: an opening balance on an account
            // that already has a statement would be a correction, and
            // corrections are adjustments.
            'opening_balance' => ['nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'opening_balance_date' => ['nullable', 'date', 'before_or_equal:today'],

            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'unlimited_credit' => $this->boolean('unlimited_credit'),
        ]);
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'You already have a customer with that code.',
            'opening_balance_date.before_or_equal' => 'An opening balance cannot be dated in the future.',
        ];
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();

        // An existing customer's opening balance is set once, at creation.
        if ($this->route('customer') instanceof Customer) {
            unset($data['opening_balance'], $data['opening_balance_date']);
        }

        return $data;
    }
}
