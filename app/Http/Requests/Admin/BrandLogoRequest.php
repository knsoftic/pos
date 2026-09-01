<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The operator's uploaded mark (#111, #101).
 *
 * Checked by CONTENT, not by the name it arrived with, and SVG is deliberately
 * absent for the same reason it is absent everywhere else in this codebase: an
 * SVG is a script container, and this one would be rendered on the login page
 * of every tenant.
 */
class BrandLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    public function rules(): array
    {
        $uploads = config('uploads.products');

        return [
            'logo' => [
                $this->boolean('remove_logo') ? 'nullable' : 'required',
                'image',
                'mimes:'.implode(',', $uploads['image_mimes']),
                'max:'.$uploads['max_kb'],
            ],
            'remove_logo' => ['boolean'],
        ];
    }
}
