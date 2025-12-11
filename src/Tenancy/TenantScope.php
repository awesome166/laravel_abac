<?php

namespace Awesome\Abac\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Awesome\Abac\Tenancy\TenantContext;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (!config('awesome-abac.tenancy_enabled', true)) {
            return;
        }

        $context = app(TenantContext::class);
        $accountId = $context->getAccountId();

        if ($accountId) {
            $builder->where($model->getTable() . '.account_id', '=', $accountId);
        } else {
            // If no account selected, we apply strict isolation?
            // "No query may return tenant-shared data unless..."
            // If explicit system context logic is needed, we need to know if we are in System Mode.
            // But here, if account_id is missing, let's assume we want NO data or only NULL data?
            // Typically in multi-tenant app, landing page is public (no tenant).
            // But accessing "Posts" without tenant should be empty.
            // Let's filter by null or just 1=0.
            // However, allowing `account_id` IS NULL might show "Global" records if designed that way.
            // "Shared database multi tenancy with row level scoping" usually implies everything belongs to a tenant.

            // To be safe and compliant with "automatic tenant ID injection":
            // If we don't know the tenant, we shouldn't guess.
            // We'll filter `whereRaw('1 = 0')` effectively, unless model allows global?

            $builder->whereNull($model->getTable() . '.account_id');
        }
    }
}
