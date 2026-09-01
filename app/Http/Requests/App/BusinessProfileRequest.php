<?php

namespace App\Http\Requests\App;

use App\Support\Format;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shop's own details (#57, #111, #154).
 *
 * The timezone is here rather than in settings because it is a fact about where
 * the shop IS. It changes how every stored timestamp is displayed and nothing
 * about how any of them are stored (#153) — see {@see Format}.
 */
class BusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        $uploads = config('uploads.products');

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],

            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'locale' => ['nullable', 'string', 'max:10'],

            'logo' => ['nullable', 'image', 'mimes:'.implode(',', $uploads['image_mimes']), 'max:'.$uploads['max_kb']],
            'remove_logo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'timezone.in' => 'That is not a timezone this server knows.',
            'name.required' => 'A shop needs a name — it goes on every receipt.',
        ];
    }

    /** @return array<string, mixed> */
    public function businessAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->input('email') ?: null,
            'phone' => $this->input('phone') ?: null,
            'address' => $this->input('address') ?: null,
            'timezone' => $this->string('timezone')->toString(),
            'locale' => $this->input('locale') ?: 'en',
        ];
    }
}
