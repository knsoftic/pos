<?php

namespace App\Http\Requests\App;

use App\Models\Sale;
use App\Services\SaleService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the till sends when the money is taken (#118, #91, #141).
 *
 * The cart arriving here is NOT trusted with anything that matters. Prices,
 * stock, credit limits and totals are all recomputed by
 * {@see SaleService} from the ids below — this class only checks
 * the request is well-formed and enforces the one rule that belongs to the
 * person rather than to the sale: their discount cap (#141).
 */
class PosCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can(PermissionRegistry::POS_OPERATE);
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            // #91: one key per cart. See the migration for why this is the real
            // protection and a disabled button is not.
            'idempotency_key' => ['nullable', 'string', 'max:64'],

            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],

            'notes' => ['nullable', 'string', 'max:1000'],

            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'payments' => ['array', 'max:10'],
            'payments.*.method' => ['required', 'string', 'max:40'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'There is nothing in the cart.',
            'customer_id.exists' => 'Choose one of your own customers.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $cap = $this->user()->discountCap();

            if ($cap === null) {
                return;
            }

            /*
             | The discount cap belongs to the PERSON (#141), so it is checked
             | here rather than in SaleService: a manager approving the same
             | basket is allowed the discount that this cashier is not, and the
             | sale itself is identical either way.
             */
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $gross = (float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0);
                $discount = (float) ($line['discount_amount'] ?? 0);

                if ($gross <= 0 || $discount <= 0) {
                    continue;
                }

                $percent = round(($discount / $gross) * 100, 2);

                if ($percent > $cap + 0.005) {
                    $validator->errors()->add(
                        "lines.{$i}.discount_amount",
                        sprintf(
                            'That is a %s%% discount and your limit is %s%%. Ask a manager to approve it.',
                            rtrim(rtrim(number_format($percent, 2), '0'), '.'),
                            rtrim(rtrim(number_format($cap, 2), '0'), '.'),
                        ),
                    );
                }
            }
        });
    }

    /**
     * A sale already made with this exact key, if the request is a repeat (#91).
     */
    public function existingSale(): ?Sale
    {
        $key = $this->input('idempotency_key');

        if (blank($key)) {
            return null;
        }

        return Sale::query()->where('idempotency_key', $key)->first();
    }

    /** @return array<string, mixed> */
    public function saleAttributes(): array
    {
        return [
            'customer_id' => $this->input('customer_id') ?: null,
            'notes' => $this->input('notes') ?: null,
            'idempotency_key' => $this->input('idempotency_key') ?: null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function lines(): array
    {
        $lines = [];

        foreach ((array) $this->input('lines', []) as $line) {
            if (! is_array($line) || blank($line['product_id'] ?? null)) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> */
    public function payments(): array
    {
        $payments = [];

        foreach ((array) $this->input('payments', []) as $payment) {
            if (! is_array($payment) || (float) ($payment['amount'] ?? 0) <= 0) {
                continue;
            }

            $payments[] = $payment;
        }

        return $payments;
    }
}
