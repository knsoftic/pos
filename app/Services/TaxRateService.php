<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Models\TaxRate;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The named tax rates a shop charges (#59).
 *
 * ⚠️ THIS SERVICE NEVER TOUCHES A SALE. Products and sale lines carry the rate
 * as a NUMBER, snapshotted when they were saved and when they sold; changing a
 * rate here changes what new lines get and restates nothing that has already
 * been printed. See the migration for why that is not negotiable.
 */
class TaxRateService
{
    public function __construct(
        protected TenantContext $tenant,
        protected FeatureService $features,
        protected AuditService $audit,
    ) {}

    /** @param  array{name: string, rate: float, is_default?: bool, is_active?: bool}  $data */
    public function create(array $data): TaxRate
    {
        $this->assertFeature();

        return DB::transaction(function () use ($data): TaxRate {
            $rate = new TaxRate([
                'name' => trim($data['name']),
                'rate' => round((float) $data['rate'], 3),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => $data['is_active'] ?? true,
            ]);
            $rate->save();

            if ($rate->is_default) {
                $this->makeSoleDefault($rate);
            }

            $this->audit->log('tax_rate.created', $rate, "Tax rate \"{$rate->label()}\" added.");

            return $rate;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(TaxRate $rate, array $data): TaxRate
    {
        $this->assertFeature();

        $before = $rate->only(['name', 'rate', 'is_default', 'is_active']);

        return DB::transaction(function () use ($rate, $data, $before): TaxRate {
            if (array_key_exists('name', $data)) {
                $rate->name = trim($data['name']);
            }

            if (array_key_exists('rate', $data)) {
                $rate->rate = round((float) $data['rate'], 3);
            }

            foreach (['is_default', 'is_active'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $rate->{$flag} = (bool) $data[$flag];
                }
            }

            // A rate that is switched off cannot also be the one offered first.
            if (! $rate->is_active) {
                $rate->is_default = false;
            }

            $rate->save();

            if ($rate->is_default) {
                $this->makeSoleDefault($rate);
            }

            $this->audit->logChange(
                'tax_rate.updated',
                $rate,
                $before,
                $rate->only(['name', 'rate', 'is_default', 'is_active']),
                "Tax rate \"{$rate->label()}\" updated.",
            );

            return $rate;
        });
    }

    /**
     * Deleted, not archived — and that is safe precisely BECAUSE products and
     * sale lines hold the number rather than a reference. Removing a rate from
     * the list changes what people can pick next; it cannot orphan a figure.
     */
    public function delete(TaxRate $rate): void
    {
        $this->assertFeature();

        $label = $rate->label();
        $rate->delete();

        $this->audit->log('tax_rate.deleted', $rate, "Tax rate \"{$label}\" removed.");
    }

    /** The rate a new product should start with, if the shop has said. */
    public function default(): ?TaxRate
    {
        return TaxRate::query()->active()->where('is_default', true)->first();
    }

    protected function makeSoleDefault(TaxRate $rate): void
    {
        // "Exactly one" is a transaction, not a constraint: the schema cannot
        // express "at most one true per business" without refusing the moment
        // between clearing the old default and setting the new one.
        TaxRate::query()
            ->where('is_default', true)
            ->whereKeyNot($rate->id)
            ->update(['is_default' => false]);
    }

    protected function assertFeature(): void
    {
        if (! $this->features->enabled(FeatureRegistry::SALES_TAX)) {
            throw new FeatureUnavailableException(FeatureRegistry::SALES_TAX, 'Tax');
        }
    }
}
