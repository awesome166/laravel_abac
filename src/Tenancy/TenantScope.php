<?php

namespace AbacPermissions\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use AbacPermissions\Tenancy\TenantContext;
use AbacPermissions\Facades\AbacPermissions;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $tenancyEnabled = config('abacpermissions.tenancy_enabled', config('abacpermissions.saas_mode', true));
        if (!$tenancyEnabled) {
            return;
        }

        $context = app(TenantContext::class);
        $accountId = $context->getAccountId();

        if ($accountId) {
            $builder->where($model->getTable() . '.account_id', '=', $accountId);
        } else {
            // If no account selected, check for System Level Zeus
            $user = auth()->user();

            // System Zeus users can access all data regardless of tenant
            // Uses efficient database query with request-level caching to avoid N+1 queries
            if ($user && AbacPermissions::isSystemZeus($user)) {
                // ZEUS OVERRIDE: Do not apply any scope. Return all data.
                return;
            }

            // Strict isolation for regular users:
            // Only show Global items (where account_id is NULL)
            // This ensures users without an account selected cannot see tenant-specific data
            $builder->whereNull($model->getTable() . '.account_id');
        }
    }
}
