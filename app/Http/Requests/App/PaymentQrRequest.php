<?php

namespace App\Http\Requests\App;

use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shop's own payment QR image (#57, #101).
 *
 * Content-checked like every other upload, and SVG is absent for the usual
 * reason — this one is shown full-screen to customers at the till.
 */
class PaymentQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        $uploads = config('uploads.products');

        return [
            'payment_qr' => [
                $this->boolean('remove_qr') ? 'nullable' : 'required',
                'image',
                'mimes:'.implode(',', $uploads['image_mimes']),
                'max:'.$uploads['max_kb'],
            ],
            'remove_qr' => ['boolean'],
        ];
    }
}
