<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the branch form (#47).
 *
 * The uniqueness rules are scoped to the business explicitly. `Rule::unique`
 * builds its own query and knows nothing about the tenant global scope, so
 * without the `where` a code taken in another business would block this one.
 */
class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::BRANCHES_MANAGE);
    }

    public function rules(): array
    {
        $branchId = $this->route('branch')?->id;
        $businessId = $this->user()->business_id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:20', 'alpha_dash',
                // Includes archived branches: their codes stay reserved so two
                // eras of history can never share one code.
                Rule::unique('branches', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($branchId),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
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
            'code.unique' => 'That branch code is already in use (archived branches keep their codes).',
        ];
    }
}
