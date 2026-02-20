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



    public function permissions(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(
            \AbacPermissions\Models\Permission::class,
            'assignee',
            'assigned_permissions',
            'assignee_id',
            'permission_id'
        );
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
    public function isTenantZeus(string|int|null $accountId = null): bool
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


    /**
     * Get all permissions assigned to the user (via roles + direct grants).
     * Returns a comma-separated string of expanded permission names.
     */
    public function getAllPermissions(): string
    {
        // Use the already-loaded collection if available (avoids extra query
        // when User has $with = ['roles'])
        $roleIds = $this->relationLoaded('roles')
            ? $this->roles->pluck('id')
            : $this->roles()->pluck('id');

        $accountId = app(\AbacPermissions\Tenancy\TenantContext::class)->getAccountId();

        // Fetch all assignments for this user's roles and direct grants
        $assignments = \AbacPermissions\Models\AssignedPermission::where(function ($query) use ($roleIds, $accountId) {
            $query->where(function ($q) use ($roleIds) {
                $q->where('assignee_type', 'role')
                    ->whereIn('assignee_id', $roleIds);
            })
            ->orWhere(function ($q) use ($accountId) {
                $q->where('assignee_type', 'user')
                    ->where('assignee_id', $this->id);
            })

            ->orWhere(function ($q) use ($accountId) {
                $q->where('assignee_type', 'account')
                    ->where('assignee_id', $accountId);
            });
        })
        ->with('permission') // eager-load so getExpandedPermissions() doesn't need to lazy-load
        ->get();

        $expandedPermissions = $assignments->flatMap(function ($assignment) {
            return $assignment->getExpandedPermissions();
        });

        return $expandedPermissions->unique()->sort()->implode(', ');
    }

    /**
     * Get the set of permissions this user is allowed to delegate to other users.
     *
     * The delegation ceiling is the actor's own permission holdings — no separate
     * account-level cap or grantable flag on user rows is required.
     *
     * Rules (checked in priority order):
     *   1. System Zeus  → all permissions, full access (uncapped)
     *   2. Tenant Zeus  → all permissions, full access (top admin of their tenant)
     *   3. Regular user → exactly what they personally hold (roles + direct assignments)
     *                     access is whatever access level they have on that permission
     *
     * Returns a Collection keyed by permission_id, value = access[] | null (null = full)
     *
     * @param  string|null $accountId  The tenant context. Falls back to TenantContext if null.
     * @return \Illuminate\Support\Collection
     */
    public function getGrantablePermissions(?string $accountId = null): \Illuminate\Support\Collection
    {
        // --- Resolve accountId from context if not given --------------------------
        if ($accountId === null) {
            $accountId = app(\AbacPermissions\Tenancy\TenantContext::class)->getAccountId();
        }

        // --- 1. System Zeus: fully uncapped ---------------------------------------
        if ($this->isSystemZeus()) {
            return \AbacPermissions\Models\Permission::all()
                ->keyBy('id')
                ->map(fn ($p) => null); // null = full access on every permission
        }

        // --- Fetch Account Cap ----------------------------------------------------
        $accountCap = collect();
        if ($accountId) {
            $account = \AbacPermissions\Models\Account::find($accountId);
            if ($account) {
                $accountCap = $account->getGrantableCap();
            }
        }

        // --- 2. Tenant Zeus: fully capped by Account Cap --------------------------
        if ($accountId && $this->isTenantZeus($accountId)) {
            return $accountCap;
        }

        // --- 3. Regular user: delegate from their own holdings --------------------
        if ($accountId === null) {
            return collect(); // no tenant context → nothing to delegate
        }

        $roleIds = $this->roles()->pluck('id');

        // Fetch all assignments the user holds (via roles or direct grants)
        $userAssignments = \AbacPermissions\Models\AssignedPermission::where(function ($query) use ($roleIds, $accountId) {
            $query->where(function ($q) use ($roleIds) {
                // Permissions coming from any of the user's roles
                $q->where('assignee_type', 'role')
                  ->whereIn('assignee_id', $roleIds);
            })
            ->orWhere(function ($q) use ($accountId) {
                // Permissions directly assigned to this user (global or account-scoped)
                $q->where('assignee_type', 'user')
                  ->where('assignee_id', $this->id)
                  ->where(function ($inner) use ($accountId) {
                      $inner->whereNull('account_id')
                            ->orWhere('account_id', $accountId);
                  });
            });
        })
        ->get()
        ->keyBy('permission_id');

        // Map to [ permission_id => access[] | null ]
        $userCap = $userAssignments->map(fn ($ap) => $ap->access);

        // Intersect User Cap with Account Cap
        $finalCap = collect();

        foreach ($userCap as $permId => $uAccess) {
            if (! $accountCap->has($permId)) {
                // Account cannot delegate this permission, so user cannot either.
                continue;
            }

            $aAccess = $accountCap[$permId];

            if ($aAccess === null) {
                $finalCap[$permId] = $uAccess;
                continue;
            }

            if ($uAccess === null) {
                $finalCap[$permId] = $aAccess;
                continue;
            }

            $intersected = array_values(array_intersect($uAccess, $aAccess));

            if (!empty($intersected)) {
                $finalCap[$permId] = $intersected;
            }
        }

        return $finalCap;
    }


    /**
     * Validate that a permissions payload is within this user's grantable cap.
     *
     * Throws \Illuminate\Auth\Access\AuthorizationException if any item
     * exceeds what the actor is allowed to delegate.
     *
     * @param  array       $permissionsPayload  e.g. [['id' => 'perm-id', 'access' => ['read']]]
     * @param  string|null $accountId
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function authorizePermissionDelegation(array $permissionsPayload, ?string $accountId = null): void
    {
        // System Zeus: bypass all delegation checks
        if ($this->isSystemZeus()) {
            return;
        }

        $cap = $this->getGrantablePermissions($accountId);

        foreach ($permissionsPayload as $item) {
            $permId = $item['id']    ?? null;
            $access = $item['access'] ?? null; // array | null

            if (!$cap->has($permId)) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "You are not allowed to delegate permission [{$permId}]."
                );
            }

            $capAccess = $cap[$permId]; // null = full, array = restricted

            // Cap is null (full) → any requested access is fine
            if ($capAccess === null) {
                continue;
            }

            // Requested full access but cap is restricted → deny
            if ($access === null) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "You cannot delegate full access for permission [{$permId}]."
                    . " Allowed: " . implode(', ', $capAccess)
                );
            }

            // Any requested action outside the cap → deny
            $denied = array_diff($access, $capAccess);
            if (!empty($denied)) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "You cannot delegate [" . implode(', ', $denied) . "] for permission [{$permId}]."
                    . " Allowed: " . implode(', ', $capAccess)
                );
            }
        }
    }

}
