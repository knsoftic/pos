<?php

namespace App\Http\Requests\App;

use App\Services\CatalogService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the category form (#26).
 *
 * `parent_id` is only checked for shape here; whether it exists in THIS business
 * and whether it would create a loop is answered by
 * {@see CatalogService}, which is the only place that can see the
 * whole tree.
 */
class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
