<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A named tax rate (#59).
 *
 * The rate is capped at 100: a tax of more than the whole price is a typo every
 * time, and letting one through would put a nonsense figure on an invoice that
 * somebody then has to explain to a tax office.
 */
class TaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('tax_rates', 'name')
                    ->where('business_id', $this->user()->business_id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('taxRate')?->id),
            ],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a rate with that name.',
            'rate.max' => 'A rate above 100% is a typo — tax cannot exceed the price.',
        ];
    }

    /** @return array<string, mixed> */
    public function rateAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'rate' => (float) $this->input('rate'),
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
