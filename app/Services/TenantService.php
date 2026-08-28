<?php

namespace App\Services;

use App\Models\Business;
use App\Support\TenantContext;

/**
 * Thin, injectable facade over {@see TenantContext} (spec #183). Controllers and
 * services depend on this rather than reaching for globals.
 */
class TenantService
{
    public function __construct(protected TenantContext $context) {}

    public function current(): ?Business
    {
        return $this->context->business();
    }

    public function currentId(): ?int
    {
        return $this->context->businessId();
    }

    public function has(): bool
    {
        return $this->context->hasBusiness();
    }

    public function set(Business $business): void
    {
        $this->context->setBusiness($business);
    }
}
