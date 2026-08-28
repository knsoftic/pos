<?php

namespace App\Http\Requests\Admin;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create / update a tenant from the operator console (#6).
 *
 * The owner-account fields are only validated on create — after that the owner
 * is managed as a user, not as part of the business record.
 */
class BusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug($this->input('slug') ?: (string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        $business = $this->business();
        $creating = $business === null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('businesses', 'slug')->ignore($business?->id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(array_keys(Business::statusOptions()))],
            'timezone' => ['required', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'locale' => ['required', 'string', 'max:10'],
        ];

        if ($creating) {
            $rules += [
                'owner_name' => ['required', 'string', 'max:255'],
                // Unique across ALL tenants: the users table is one namespace, so
                // an email already in use anywhere cannot log in unambiguously.
                'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'owner_phone' => ['nullable', 'string', 'max:40'],
                'owner_password' => ['required', 'confirmed', Password::defaults()],

                'plan_id' => ['required', 'exists:plans,id'],
                'billing_cycle' => ['required', 'string'],
                'start_trial' => ['nullable', 'boolean'],
                'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'owner_name' => 'owner name',
            'owner_email' => 'owner email',
            'owner_password' => 'owner password',
            'plan_id' => 'plan',
            'billing_cycle' => 'billing cycle',
        ];
    }

    public function business(): ?Business
    {
        $business = $this->route('business');

        return $business instanceof Business ? $business : null;
    }

    /** @return array<string, mixed> */
    public function businessAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'slug' => $this->string('slug')->toString(),
            'email' => $this->input('email'),
            'phone' => $this->input('phone'),
            'address' => $this->input('address'),
            'status' => $this->string('status')->toString(),
            'timezone' => $this->string('timezone')->toString(),
            'locale' => $this->string('locale')->toString(),
        ];
    }
}
