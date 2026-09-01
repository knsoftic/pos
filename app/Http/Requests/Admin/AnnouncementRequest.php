<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An announcement (#77).
 *
 * The end date must be after the start, which sounds obvious until somebody
 * types the same day twice and the notice never appears at all — a silent
 * failure the operator would only discover by asking a shop.
 */
class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'level' => ['required', 'in:info,warning,danger'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
            'is_dismissible' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after' => 'The end has to come after the start, or nobody ever sees it.',
        ];
    }

    /** @return array<string, mixed> */
    public function announcementAttributes(): array
    {
        return [
            'title' => $this->string('title')->toString(),
            'body' => $this->string('body')->toString(),
            'level' => $this->string('level')->toString(),
            'starts_at' => $this->input('starts_at') ?: null,
            'ends_at' => $this->input('ends_at') ?: null,
            'is_active' => $this->boolean('is_active'),
            'is_dismissible' => $this->boolean('is_dismissible'),
        ];
    }
}
