<?php

namespace App\Http\Requests\App;

use App\Models\User;
use App\Services\EmployeeService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for the employee form (#50, #138, #141).
 *
 * Emails are unique across the WHOLE system, not per business: they are the
 * login identifier for the `web` guard, so two tenants cannot share one. The
 * rule ignores soft-deleted staff on purpose — a removed employee's address
 * stays taken until their record is really gone, otherwise re-adding someone
 * would collide with their own history.
 */
class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::EMPLOYEES_MANAGE);
    }

    public function rules(): array
    {
        $employeeId = $this->employee()?->id;
        $businessId = $this->user()->business_id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($employeeId),
            ],
            'phone' => ['nullable', 'string', 'max:40'],

            // Required when creating, optional when editing — an empty field on
            // edit means "leave the password alone".
            'password' => [
                $employeeId === null ? 'required' : 'nullable',
                'confirmed',
                Password::defaults(),
            ],

            // Both must belong to THIS business. The service checks again, and
            // additionally checks the acting user may reach the branch (#48).
            'role_id' => [
                'nullable', 'integer',
                Rule::exists('roles', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'pos_counter_id' => [
                'nullable', 'integer',
                Rule::exists('pos_counters', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],

            // Blank = no cap, 0 = no discounts at all. Both are real answers, so
            // the field is nullable rather than defaulted (#141).
            'max_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'That email address is already registered.',
            'role_id.exists' => 'Choose one of your own roles.',
            'branch_id.exists' => 'Choose one of your own branches.',
            'pos_counter_id.exists' => 'Choose one of your own counters.',
        ];
    }

    /**
     * The shape {@see EmployeeService} expects. The password key is
     * omitted entirely when left blank, so `update()` can tell "unchanged" from
     * "set to empty".
     *
     * @return array<string, mixed>
     */
    public function employeeAttributes(): array
    {
        $data = [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'phone' => $this->input('phone') ?: null,
            'role_id' => $this->input('role_id') ?: null,
            'branch_id' => $this->input('branch_id') ?: null,
            'pos_counter_id' => $this->input('pos_counter_id') ?: null,
            'max_discount_percent' => $this->input('max_discount_percent'),
            'is_active' => $this->boolean('is_active'),
        ];

        if (filled($this->input('password'))) {
            $data['password'] = $this->string('password')->toString();
        }

        return $data;
    }

    public function employee(): ?User
    {
        $employee = $this->route('employee');

        return $employee instanceof User ? $employee : null;
    }
}
