<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for an expense heading (#43).
 *
 * Uniqueness is per business and IGNORES the archived: two live "Rent"
 * categories would make the dropdown a guess, but an archived one holding last
 * year's figures must not block the shop from having a live one again.
 */
class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::EXPENSES_MANAGE);
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;
        $categoryId = $this->route('category')?->id;

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('expense_categories', 'name')
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')
                    ->ignore($categoryId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a category with that name.',
        ];
    }

    /** @return array<string, mixed> */
    public function categoryAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->input('description') ?: null,
            'is_active' => $this->boolean('is_active'),
            'sort_order' => (int) $this->input('sort_order', 0),
        ];
    }
}
