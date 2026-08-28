<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the unit form (#26, #158).
 *
 * The short name is what appears next to every quantity in the app, so it is
 * unique per business — two units both called "kg" would make a stock figure
 * ambiguous.
 */
class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        $unitId = $this->route('unit')?->id;
        $businessId = $this->user()->business_id;

        return [
            'name' => ['required', 'string', 'max:60'],
            'short_name' => [
                'required', 'string', 'max:12',
                Rule::unique('units', 'short_name')
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')
                    ->ignore($unitId),
            ],
            'base_unit_id' => ['nullable', 'integer'],
            // Only meaningful with a base unit; the service rejects zero and
            // negatives, this keeps obvious nonsense out of the service.
            'conversion_factor' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'allows_decimals' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'allows_decimals' => $this->boolean('allows_decimals'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'short_name.unique' => 'You already have a unit with that short name.',
        ];
    }
}
