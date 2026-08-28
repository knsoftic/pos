<?php

namespace App\Models\Scopes;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that narrows a branch-owned model to the branches the current
 * user may reach (#48). Runs UNDERNEATH {@see TenantScope}, never instead of it:
 * tenancy decides which business, this decides which shops inside it.
 *
 * With no restriction in {@see BranchContext} (owner, console, queued jobs) it
 * is a no-op. With a restriction and an empty list it adds an impossible
 * condition — an employee with no branch sees nothing, rather than everything.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(BranchContext::class);

        if (! $context->isRestricted()) {
            return;
        }

        $branchIds = $context->branchIds() ?? [];
        $column = $model->getTable().'.'.$model->getBranchColumn();

        if ($branchIds === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($column, $branchIds);
    }
}
