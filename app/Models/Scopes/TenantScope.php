<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains a tenant-owned model to the current business.
 *
 * It only adds a WHERE clause when a business is present in {@see TenantContext}.
 * With no active context (super admin / console) it is a no-op, and callers are
 * expected to scope explicitly. See BelongsToTenant for the full contract.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasBusiness()) {
            $builder->where(
                $model->getTable().'.'.$model->getTenantColumn(),
                $context->businessId()
            );
        }
    }
}
