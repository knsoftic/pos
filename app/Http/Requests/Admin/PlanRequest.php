<?php

namespace App\Http\Requests\Admin;

use App\Enums\BillingCycle;
use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation for the single-page plan editor: details, per-cycle prices, the
 * feature matrix and the quota matrix all save together in one transaction.
 *
 * Used for both store and update — `plan()` is null on create.
 */
class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Reaching this route already required the `auth:admin` guard. Granular
        // operator roles are Phase 3.
        return $this->user('admin') !== null;
    }

    protected function prepareForValidation(): void
    {
        // A blank slug is derived from the name — operators should not have to
        // think about URL keys.
        $this->merge([
            'slug' => Str::slug($this->input('slug') ?: (string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        $planId = $this->plan()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                // Ignores soft-deleted plans on purpose: an archived plan still
                // owns its slug, because live subscriptions still point at it.
                Rule::unique('plans', 'slug')->ignore($planId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'badge' => ['nullable', 'string', 'max:40'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],

            // ---- prices -----------------------------------------------------
            'prices' => ['array'],
            'prices.*.enabled' => ['nullable', 'boolean'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'prices.*.custom_days' => ['nullable', 'integer', 'min:1', 'max:36500'],

            // ---- matrices (keyed by row id, not code) -------------------------
            'features' => ['array'],
            'features.*' => ['nullable', 'integer', 'exists:features,id'],

            // Three states, matching the resolver contract exactly: `inherit`
            // writes NO pivot row (fall through to the registry default),
            // `unlimited` writes NULL, `custom` writes the number.
            'limits' => ['array'],
            'limits.*.mode' => ['nullable', Rule::in(['inherit', 'unlimited', 'custom'])],
            'limits.*.value' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'limits.*.unlimited' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // A plan nobody can be charged for is almost always a mistake — and
            // SubscriptionService refuses to subscribe anyone to it, so catching
            // it here is far friendlier than failing at assignment time.
            $enabled = collect($this->input('prices', []))
                ->filter(fn ($row) => ! empty($row['enabled']));

            if ($enabled->isEmpty()) {
                $validator->errors()->add('prices', 'Enable at least one billing cycle and give it a price (0 is allowed for a free plan).');
            }

            // Custom cycles are meaningless without a length.
            foreach ($this->input('prices', []) as $cycle => $row) {
                if (empty($row['enabled'])) {
                    continue;
                }

                if (BillingCycle::tryFrom((string) $cycle)?->requiresCustomDays() && empty($row['custom_days'])) {
                    $validator->errors()->add("prices.{$cycle}.custom_days", 'A custom cycle needs a length in days.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'is_public' => 'public visibility',
            'sort_order' => 'display order',
        ];
    }

    /** The plan being edited, or null when creating. */
    public function plan(): ?Plan
    {
        $plan = $this->route('plan');

        return $plan instanceof Plan ? $plan : null;
    }

    /**
     * The plan's own columns, ready for create/update.
     *
     * @return array<string, mixed>
     */
    public function planAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'slug' => $this->string('slug')->toString(),
            'description' => $this->input('description'),
            'badge' => $this->input('badge'),
            'trial_days' => $this->input('trial_days') === null ? null : (int) $this->input('trial_days'),
            'grace_days' => $this->input('grace_days') === null ? null : (int) $this->input('grace_days'),
            'sort_order' => (int) ($this->input('sort_order') ?? 0),
            'is_active' => $this->boolean('is_active'),
            'is_public' => $this->boolean('is_public'),
        ];
    }

    /**
     * Enabled cycles only, normalised.
     *
     * @return array<string, array{price: float, custom_days: int|null}>
     */
    public function priceRows(): array
    {
        $rows = [];

        foreach ($this->input('prices', []) as $cycle => $row) {
            if (empty($row['enabled']) || BillingCycle::tryFrom((string) $cycle) === null) {
                continue;
            }

            $rows[$cycle] = [
                'price' => (float) ($row['price'] ?? 0),
                'custom_days' => isset($row['custom_days']) && $row['custom_days'] !== null && $row['custom_days'] !== ''
                    ? (int) $row['custom_days']
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Feature ids the operator ticked.
     *
     * @return list<int>
     */
    public function enabledFeatureIds(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            (array) $this->input('features', []),
        )));
    }

    /**
     * Quota values keyed by limit id, ready for the `plan_limit` pivot.
     *
     * Three-state, and the states are NOT interchangeable:
     *   inherit   → omitted entirely, so no pivot row exists and the resolver
     *               falls through to the registry default
     *   unlimited → NULL
     *   custom    → the integer (blank counts as 0 = nothing allowed)
     *
     * `unlimited` is still honoured as a plain checkbox for callers that post
     * the simpler shape.
     *
     * @return array<int, int|null>
     */
    public function limitValues(): array
    {
        $values = [];

        foreach ($this->input('limits', []) as $limitId => $row) {
            $mode = $row['mode'] ?? (! empty($row['unlimited']) ? 'unlimited' : 'custom');

            if ($mode === 'inherit') {
                continue;
            }

            $raw = $row['value'] ?? null;

            $values[(int) $limitId] = $mode === 'unlimited'
                ? null
                : (int) ($raw === '' || $raw === null ? 0 : $raw);
        }

        return $values;
    }
}
