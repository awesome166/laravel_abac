<?php

namespace Awesome\Abac\AccessControl;

use Illuminate\Support\Facades\Cache;
use Awesome\Abac\Tenancy\TenantContext;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Models\Permission;

class AbacEngine
{
    protected TenantContext $config;

    public function __construct(TenantContext $config)
    {
        $this->config = $config;
    }

    /**
     * Get all effective permissions for a user in the current context.
     * Includes caching and CRUD expansion.
     * Includes Zeus bypass logic (which results in a generic '*' permission or check mechanism).
     * Auto-recaches if cache is empty.
     */
    public function getPermissions($user): array
    {
        if (!$user) return [];

        $accountId = $this->config->getAccountId();
        $version = Cache::get('awesome_abac_version', 1);
        $cacheKey = "awesome_abac_{$version}_perms_{$user->id}_" . ($accountId ?? 'global');

        $permissions = Cache::remember($cacheKey, config('awesome-abac.cache.ttl', 3600), function () use ($user, $accountId) {
             return $this->resolvePermissions($user, $accountId);
        });

        // Auto-recache if cache is empty (user requested this feature)
        if (empty($permissions)) {
            Cache::forget($cacheKey);
            $permissions = $this->resolvePermissions($user, $accountId);

            if (!empty($permissions)) {
                Cache::put($cacheKey, $permissions, config('awesome-abac.cache.ttl', 3600));
            }
        }

        return $permissions;
    }

    public function hasPermission($user, string $permission): bool
    {
        $perms = $this->getPermissions($user);

        // Check for System Level Zeus (Universal Bypass)
        if (in_array('*', $perms)) {
            return true;
        }

        // Check for Tenant Level Zeus (Bypass for this tenant)
        // If we are in a tenant context, and user has 'tenant:*' or similar marker.
        // My implementation returns '*' if System Zeus, and 'tenant:*' if Tenant Zeus?
        // Or cleaner: `resolvePermissions` handles the expansion logic and adds special flags.

        return in_array($permission, $perms);
    }

    /**
     * Clear cache for a user.
     */
    public function flushCache($user, $accountId = null)
    {
        // Ideally clear global AND tenant specific.
        // For simplicity, we might iterate or use tags if supported.
        // Without tags, we can't easily clear "all contexts" unless we know them.
        // We will just clear the specific key if known, or assume the user logs out/cache expires.
        // Or better: Use Cache Tags if available: ['abac_user_{id}'].

        if (Cache::supportsTags()) {
            Cache::tags(["abac_user_{$user->id}"])->flush();
        } else {
             // Fallback: Clear current context
             $key = "awesome_abac_perms_{$user->id}_" . ($accountId ?? 'global');
             Cache::forget($key);
        }
    }

    protected function resolvePermissions($user, $accountId): array
    {
        $allPermissions = [];
        $isTenant = !is_null($accountId);

        // 1. Get Assigned Roles
        $roles = $user->roles ?? collect([]);

        // Filter roles relevant to this account (Global + Tenant specific)
        $applicableRoles = $roles->filter(function ($role) use ($accountId) {
            return is_null($role->account_id) || $role->account_id == $accountId;
        });

        // 2. Check for Zeus
        // System Zeus: Role with zeus_level='system'
        if ($applicableRoles->contains(fn($r) => $r->isSystemZeus())) {
            return ['*']; // Full bypass
        }

        // Tenant Zeus: Role with zeus_level='tenant' AND currently in that tenant
        if ($isTenant && $applicableRoles->contains(fn($r) => $r->isTenantZeus() && $r->account_id == $accountId)) {
            return ['*']; // In this context, they have everything
        }

        // 3. Collect Permissions from Roles via AssignedPermission
        $roleIds = $applicableRoles->pluck('id')->toArray();

        if (!empty($roleIds)) {
            $roleAssignments = \Awesome\Abac\Models\AssignedPermission::query()
                ->where('assignee_type', 'role')
                ->whereIn('assignee_id', $roleIds)
                ->with('permission')
                ->get();

            foreach ($roleAssignments as $assignment) {
                $allPermissions = array_merge($allPermissions, $assignment->getExpandedPermissions());
            }
        }

        // 4. Collect Direct User Permissions via AssignedPermission
        $userAssignments = \Awesome\Abac\Models\AssignedPermission::query()
            ->where('assignee_type', 'user')
            ->where('assignee_id', $user->id)
            ->forAccount($accountId)
            ->with('permission')
            ->get();

        foreach ($userAssignments as $assignment) {
            $allPermissions = array_merge($allPermissions, $assignment->getExpandedPermissions());
        }

        return array_unique($allPermissions);
    }
}
