<?php

namespace Zeus\Tenancy\Database;

use Illuminate\Database\Query\Builder;
use Zeus\Tenancy\TenantManager;

class QueryMixin
{
    public function withTenant()
    {
        return function () {
            /** @var Builder $this */
            $manager = app(TenantManager::class);
            if ($manager->isSystemContext()) {
                return $this;
            }

            $tenantId = $manager->getTenantId();
            if ($tenantId) {
                // Assuming the table is already set and we just append where
                // CAUTION: for joins, ambiguous column might be an issue.
                // Simple version just adds where(tenant_id).
                // Ideally, user should ensure column name is unambiguous if joining.
                $column = config('zeus-tenancy.tenant_column', 'tenant_id');
                return $this->where($column, $tenantId);
            }

            // Fallback for security: if tenant is not known, show nothing?
            // Same logic as Eloquent Scope
             $column = config('zeus-tenancy.tenant_column', 'tenant_id');
             return $this->whereNull($column);
        };
    }

    public function withoutTenant()
    {
        return function () {
            // This macro doesn't really "remove" a constraint that wasn't added yet,
            // but it serves as a semantic marker or could be used if we had auto-application.
            // Since Query Builder doesn't have "global scopes" like Eloquent, `withoutTenant`
            // on a raw query builder is mostly a no-op unless we implement a connection wrapper that forces it.
            // But if we are using this to *bypass* our manual `withTenant` calls, it's just chainable.
            // But wait, the prompt asks for `withoutTenant`.

            // If we implement the Connection Wrapper that auto-applies, then logic would be needed here.
            // For now, let's just return $this.
            return $this;
        };
    }
}
