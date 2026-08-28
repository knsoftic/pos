<?php

namespace App\Support;

use App\Models\Business;

/**
 * Holds the "current business" for the lifetime of a request (bound as a
 * singleton). This is the SINGLE SOURCE OF TRUTH for tenancy:
 *
 *   - When a business is set, every model using {@see \App\Models\Concerns\BelongsToTenant}
 *     is automatically filtered to that business_id AND has its business_id
 *     stamped on create.
 *   - When NO business is set (super-admin panel, console, queued jobs, and the
 *     pre-auth part of a request), tenant models are NOT auto-filtered — such
 *     callers must scope explicitly (e.g. ->forBusiness($id)).
 *
 * The business is resolved from the AUTHENTICATED USER by SetBusinessTenant
 * middleware — never from request input. This is what guarantees isolation.
 */
class TenantContext
{
    protected ?Business $business = null;

    public function setBusiness(Business $business): void
    {
        $this->business = $business;
    }

    public function business(): ?Business
    {
        return $this->business;
    }

    public function businessId(): ?int
    {
        return $this->business?->id;
    }

    public function hasBusiness(): bool
    {
        return $this->business !== null;
    }

    public function forget(): void
    {
        $this->business = null;
    }

    /**
     * Temporarily run a callback under a specific business context, restoring the
     * previous context afterwards. Useful for jobs / cross-tenant admin tasks.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public function runFor(Business $business, callable $callback)
    {
        $previous = $this->business;
        $this->business = $business;

        try {
            return $callback();
        } finally {
            $this->business = $previous;
        }
    }
}
