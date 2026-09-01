<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A stranger creating an account (#109, #62).
 *
 * ⚠️ This is the ONLY form in the system a person who is not signed in can use
 * to write rows, so it is the strictest. Nothing here becomes a `business_id`
 * or an `is_business_owner` — those are set by the service, never taken from
 * the request (#132).
 *
 * The email is unique across ALL users, not per business: it is the login, and
 * two accounts sharing one would make signing in ambiguous.
 */
class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Truly public. The registration switch is enforced in the service,
        // where it also guards the write itself.
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:150'],

            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],

            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],

            // A checkbox somebody actually has to tick. Consent that is assumed
            // is not consent.
            'terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => 'What is the shop called? It goes on every receipt.',
            'email.unique' => 'That email already has an account — try signing in instead.',
            'terms.accepted' => 'Please accept the terms to continue.',
        ];
    }

    /** @return array<string, mixed> */
    public function registrationAttributes(): array
    {
        return [
            'business_name' => $this->string('business_name')->toString(),
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->lower()->toString(),
            'phone' => $this->input('phone') ?: null,
            'password' => $this->string('password')->toString(),
        ];
    }
}
