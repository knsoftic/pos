<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to ANY model that belongs to a business (products, sales, customers, …).
 *
 * Guarantees, driven by {@see TenantContext}:
 *   1. Reads are auto-filtered to the current business (via {@see TenantScope}).
 *   2. Writes are auto-stamped with the current business_id — and, when a context
 *      is active, that value is AUTHORITATIVE: it overrides whatever business_id
 *      was set on the model. Combined with keeping `business_id` out of $fillable,
 *      this makes it impossible for a request to create/move a row into another
 *      tenant.
 *
 * Escape hatches (forBusiness / allTenants) exist for super-admin & system code
 * that must legitimately cross tenants; these are explicit by design.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            if ($context->hasBusiness()) {
                $model->{$model->getTenantColumn()} = $context->businessId();
            }
        });
    }

    /**
     * The column that stores the tenant key. Override with a TENANT_COLUMN
     * constant on the model if it differs from `business_id`.
     */
    public function getTenantColumn(): string
    {
        return defined(static::class.'::TENANT_COLUMN') ? static::TENANT_COLUMN : 'business_id';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, $this->getTenantColumn());
    }

    /** Query a specific business, ignoring the ambient tenant context. */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->getTenantColumn(), $businessId);
    }

    /** Query across all tenants (super-admin / reporting only). */
    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
