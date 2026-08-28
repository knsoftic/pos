<?php

namespace App\Http\Requests\App;

use App\Services\PosCounterService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the POS counter form (#49).
 *
 * `branch_id` is checked for existence WITHIN this business here, and checked
 * again for reachability in {@see PosCounterService} — the first
 * gives the user a field error, the second is the one that actually protects the
 * data.
 */
class PosCounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::POS_COUNTERS_MANAGE);
    }

    public function rules(): array
    {
        $counterId = $this->route('counter')?->id;
        $businessId = $this->user()->business_id;

        return [
            'branch_id' => [
                'required', 'integer',
                Rule::exists('branches', 'id')
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:20', 'alpha_dash',
                Rule::unique('pos_counters', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($counterId),
            ],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'code' => $this->input('code') ? strtoupper(trim((string) $this->input('code'))) : null,
        ]);
    }

    public function messages(): array
    {
        return [
            'branch_id.exists' => 'Choose one of your own branches.',
            'code.unique' => 'That counter code is already in use.',
        ];
    }
}
