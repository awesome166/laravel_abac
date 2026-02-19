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
     * Get all permissions assigned to the user, their roles, or the current account context.
     *
     * @return \Illuminate\Support\Collection
     */
        public function getAllPermissions(): string
    {
        $roleIds = $this->roles()->pluck('id');
        $accountId = app(\AbacPermissions\Tenancy\TenantContext::class)->getAccountId();

        // Get all assigned permissions for this user's roles and direct assignments
        $assignments = \AbacPermissions\Models\AssignedPermission::where(function ($query) use ($roleIds, $accountId) {
            $query->where(function ($q) use ($roleIds) {
                $q->where('assignee_type', 'role')
                    ->whereIn('assignee_id', $roleIds);
            })
            ->orWhere(function ($q) use ($accountId) {
                $q->where('assignee_type', 'user')
                    ->where('assignee_id', $this->id);
            });
        })
        ->with('permission')
        ->get();

        // Expand each assignment and collect all permission strings
        $expandedPermissions = $assignments->flatMap(function ($assignment) {
            return $assignment->getExpandedPermissions();
        });

        // Return unique permissions as comma-separated string
        return $expandedPermissions->unique()->sort()->implode(', ');
    }

    /**
     * Get the set of permissions this user is allowed to delegate to other users.
     *
     * Returns a Collection keyed by permission_id, value = access[] (or null = full access).
     *
     * Rules (checked in priority order):
     *   1. System Zeus  → all permissions with null access (full, uncapped)
     *   2. Tenant Zeus  → the account's grantable cap verbatim (they ARE the tenant super-admin)
     *   3. Regular user → intersection of (user's personal assignments) ∩ (account grantable cap)
     *                     access = common subset of both sides
     *
     * @param  string|null $accountId  The tenant context. Falls back to TenantContext if null.
     * @return \Illuminate\Support\Collection  keyed by permission_id => access[] | null
     */
    public function getGrantablePermissions(?string $accountId = null): \Illuminate\Support\Collection
    {
        // --- Resolve accountId from context if not given --------------------------
        if ($accountId === null) {
            $accountId = app(\AbacPermissions\Tenancy\TenantContext::class)->getAccountId();
        }

        // --- 1. System Zeus: no cap at all ----------------------------------------
        if ($this->isSystemZeus()) {
            return \AbacPermissions\Models\Permission::all()
                ->keyBy('id')
                ->map(fn ($p) => null); // null = full access
        }

        // We need an account to apply any cap
        if ($accountId === null) {
            return collect();
        }

        $account = \AbacPermissions\Models\Account::find($accountId);

        if (!$account) {
            return collect();
        }

        // Account-level grantable cap: [ permission_id => access[] | null ]
        $accountCap = $account->getGrantableCap();

        // --- 2. Tenant Zeus: hand back the full account cap -----------------------
        if ($this->isTenantZeus($accountId)) {
            return $accountCap;
        }

        // --- 3. Regular user: intersection ----------------------------------------
        // Collect the user's own assignments (roles + direct) for this account
        $roleIds = $this->roles()->pluck('id');

        $userAssignments = \AbacPermissions\Models\AssignedPermission::where(function ($query) use ($roleIds, $accountId) {
            $query->where(function ($q) use ($roleIds) {
                $q->where('assignee_type', 'role')
                  ->whereIn('assignee_id', $roleIds);
            })
            ->orWhere(function ($q) use ($accountId) {
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

        // Intersect user's assignments with the account cap
        $grantable = collect();

        foreach ($accountCap as $permissionId => $capAccess) {
            if (!$userAssignments->has($permissionId)) {
                continue; // user doesn't hold this permission at all
            }

            $userAccess = $userAssignments[$permissionId]->access; // array | null

            // Compute the effective grantable access
            if ($capAccess === null && $userAccess === null) {
                // Both sides full access → delegate full access
                $grantable[$permissionId] = null;
            } elseif ($capAccess === null) {
                // Cap is full, user has restricted access → delegate what user has
                $grantable[$permissionId] = $userAccess;
            } elseif ($userAccess === null) {
                // User has full access, cap is restricted → cap wins
                $grantable[$permissionId] = $capAccess;
            } else {
                // Both restricted → intersect
                $intersection = array_values(array_intersect($userAccess, $capAccess));
                if (!empty($intersection)) {
                    $grantable[$permissionId] = $intersection;
                }
                // Empty intersection means user cannot delegate this permission → skip
            }
        }

        return $grantable;
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
