<?php

namespace App\Http\Requests\App;

use App\Services\PurchaseService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for receiving a delivery (#35, #119).
 *
 * The quantities themselves are only shape-checked here; whether a line is over
 * what was ordered is answered by {@see PurchaseService}, which is
 * the only thing that can see what has already been received.
 *
 * `pay_now` is optional: paying at the door and paying later are both ordinary,
 * and neither should be the one the form insists on.
 */
class PurchaseReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::PURCHASES_CREATE);
    }

    public function rules(): array
    {
        return [
            'received' => ['array'],
            'received.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],

            'received_date' => ['nullable', 'date', 'before_or_equal:today'],

            'pay_now' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'payment_method' => ['nullable', Rule::in(config('subscription.payment_methods', []))],
            'payment_reference_no' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'received_date.before_or_equal' => 'A delivery cannot be dated in the future.',
        ];
    }

    /**
     * Item id => quantity arriving now.
     *
     * Blank boxes are dropped rather than treated as zero: leaving a line alone
     * means "all of what is outstanding", which is what the service assumes.
     *
     * @return array<int, float>
     */
    public function receivedQuantities(): array
    {
        $quantities = [];

        foreach ((array) $this->input('received', []) as $itemId => $quantity) {
            if ($quantity === null || $quantity === '') {
                continue;
            }

            $quantities[(int) $itemId] = (float) $quantity;
        }

        return $quantities;
    }
}
