<?php

namespace AbacPermissions\Traits;

use AbacPermissions\Models\Role;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Account;
use Illuminate\Support\Facades\Cache;

trait HasAbac
{
    public function accounts()
    {
        return $this->belongsToMany(
            Account::class,
            config('abacpermissions.tables.account_user', 'account_user'),
            'user_id',
            'account_id'
        );
    }

    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'role_user',
            'user_id',
            'role_id'
        );
    }

    /**
     * Assigned permissions (polymorphic relationship)
     */
    public function assignedPermissions()
    {
        return $this->morphMany(
            \AbacPermissions\Models\AssignedPermission::class,
            'assignee',
            'assignee_type',
            'assignee_id'
        );
    }

    /**
     * Get all permissions for this user (through assignments)
     */
    public function permissions()
    {
        return \AbacPermissions\Models\Permission::whereHas('assignments', function ($query) {
            $query->where('assignee_type', 'user')
                ->where('assignee_id', $this->id);
        });
    }

    /**
     * Get permissions with their access restrictions and account scope
     */
    public function getPermissionsWithAccess($accountId = null)
    {
        $query = $this->assignedPermissions()->with('permission');

        if ($accountId !== null) {
            $query->forAccount($accountId);
        }

        return $query->get();
    }

    // Cache clearing observer logic could be here or in a separate Observer class.
    // Ideally use Observer.

    /**
     * Check if this user has System Zeus role (bypasses all tenant restrictions globally).
     * Uses request-level caching to avoid repeated database queries.
     *
     * @return bool
     */
    public function isSystemZeus(): bool
    {
        // Cache for the request lifecycle to avoid N+1 queries
        $cacheKey = 'is_system_zeus_' . $this->id;

        if (!request()->attributes->has($cacheKey)) {
            $isZeus = $this->roles()
                ->where('zeus_level', 'system')
                ->exists();

            request()->attributes->set($cacheKey, $isZeus);
        }

        return request()->attributes->get($cacheKey);
    }

    /**
     * Check if this user has Tenant Zeus role for the specified account.
     * If no account ID is provided, uses the current tenant context.
     *
     * @param int|null $accountId
     * @return bool
     */
    public function isTenantZeus(?int $accountId = null): bool
    {
        // If no account specified, use current context
        if ($accountId === null) {
            $accountId = app(\AbacPermissions\Tenancy\TenantContext::class)->getAccountId();
        }

        // No account context means no tenant zeus applicable
        if ($accountId === null) {
            return false;
        }

        // Cache for the request lifecycle
        $cacheKey = 'is_tenant_zeus_' . $this->id . '_' . $accountId;

        if (!request()->attributes->has($cacheKey)) {
            $isZeus = $this->roles()
                ->where('zeus_level', 'tenant')
                ->where('account_id', $accountId)
                ->exists();

            request()->attributes->set($cacheKey, $isZeus);
        }

        return request()->attributes->get($cacheKey);
    }

    /**
     * Check if user has any Zeus level (System or Tenant) in current context.
     *
     * @return bool
     */
    public function isZeus(): bool
    {
        return $this->isSystemZeus() || $this->isTenantZeus();
    }
}
