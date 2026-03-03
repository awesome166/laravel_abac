<?php

namespace AbacPermissions\AccessControl;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use AbacPermissions\Tenancy\TenantContext;

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
        $cacheKey = $this->makeCacheKey($user->id, $accountId);
        $ttl = config('abacpermissions.cache.ttl', 3600);

        if ($this->supportsTags()) {
            $permissions = Cache::tags($this->userCacheTags($user->id))
                ->remember($cacheKey, $ttl, function () use ($user, $accountId) {
                    return $this->resolvePermissions($user, $accountId);
                });
        } else {
            $permissions = Cache::remember($cacheKey, $ttl, function () use ($user, $accountId) {
                return $this->resolvePermissions($user, $accountId);
            });
        }

        // Auto-recache if cache is empty (user requested this feature)
        if (empty($permissions)) {
            if ($this->supportsTags()) {
                Cache::tags($this->userCacheTags($user->id))->forget($cacheKey);
            } else {
                Cache::forget($cacheKey);
            }
            $permissions = $this->resolvePermissions($user, $accountId);

            if (!empty($permissions)) {
                if ($this->supportsTags()) {
                    Cache::tags($this->userCacheTags($user->id))->put($cacheKey, $permissions, $ttl);
                } else {
                    Cache::put($cacheKey, $permissions, $ttl);
                }
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

    public function isSystemZeus($user): bool
    {
        if (!$user || !isset($user->id)) {
            return false;
        }

        if (method_exists($user, 'isSystemZeus')) {
            return (bool) $user->isSystemZeus();
        }

        $rolesTable = config('abacpermissions.tables.roles', 'roles');

        return DB::table('role_user')
            ->join($rolesTable, "{$rolesTable}.id", '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->where("{$rolesTable}.zeus_level", 'system')
            ->exists();
    }

    public function isTenantZeus($user, $accountId = null): bool
    {
        if (!$user || !isset($user->id) || $accountId === null) {
            return false;
        }

        if (method_exists($user, 'isTenantZeus')) {
            return (bool) $user->isTenantZeus($accountId);
        }

        $rolesTable = config('abacpermissions.tables.roles', 'roles');

        return DB::table('role_user')
            ->join($rolesTable, "{$rolesTable}.id", '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->where("{$rolesTable}.zeus_level", 'tenant')
            ->where("{$rolesTable}.account_id", $accountId)
            ->exists();
    }

    public function getGrantablePermissions($user, $accountId = null): \Illuminate\Support\Collection
    {
        if (!$user || !isset($user->id)) {
            return collect();
        }

        if (method_exists($user, 'getGrantablePermissions')) {
            return $user->getGrantablePermissions($accountId);
        }

        if ($accountId === null) {
            $accountId = $this->config->getAccountId();
        }

        if ($this->isSystemZeus($user)) {
            return \AbacPermissions\Models\Permission::query()
                ->get()
                ->keyBy('id')
                ->map(fn ($p) => null);
        }

        $accountCap = collect();
        if ($accountId) {
            $account = \AbacPermissions\Models\Account::find($accountId);
            if ($account) {
                $accountCap = $account->getGrantableCap();
            }
        }

        if ($accountId && $this->isTenantZeus($user, $accountId)) {
            return $accountCap;
        }

        if ($accountId === null) {
            return collect();
        }

        $roleIds = $this->getApplicableRoleIds($user->id, $accountId);

        $userAssignments = \AbacPermissions\Models\AssignedPermission::where(function ($query) use ($roleIds, $user, $accountId) {
            $query->where(function ($q) use ($roleIds) {
                $q->where('assignee_type', 'role')
                    ->whereIn('assignee_id', $roleIds);
            })
            ->orWhere(function ($q) use ($user, $accountId) {
                $q->where('assignee_type', 'user')
                    ->where('assignee_id', $user->id)
                    ->where(function ($inner) use ($accountId) {
                        $inner->whereNull('account_id')
                            ->orWhere('account_id', $accountId);
                    });
            });
        })
        ->get()
        ->keyBy('permission_id');

        $userCap = $userAssignments->map(fn ($ap) => $ap->access);
        $finalCap = collect();

        foreach ($userCap as $permId => $uAccess) {
            if (! $accountCap->has($permId)) {
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

    public function authorizePermissionDelegation($user, array $permissionsPayload, $accountId = null): void
    {
        if (!$user || !isset($user->id)) {
            return;
        }

        if (method_exists($user, 'authorizePermissionDelegation')) {
            $user->authorizePermissionDelegation($permissionsPayload, $accountId);
            return;
        }

        if ($this->isSystemZeus($user)) {
            return;
        }

        $cap = $this->getGrantablePermissions($user, $accountId);

        foreach ($permissionsPayload as $item) {
            $permId = $item['id'] ?? null;
            $access = $item['access'] ?? null;

            if (!$cap->has($permId)) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "You are not allowed to delegate permission [{$permId}]."
                );
            }

            $capAccess = $cap[$permId];
            if ($capAccess === null) {
                continue;
            }

            if ($access === null) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "You cannot delegate full access for permission [{$permId}]. Allowed: " . implode(', ', $capAccess)
                );
            }

            $denied = array_diff($access, $capAccess);
            if (!empty($denied)) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    "You cannot delegate [" . implode(', ', $denied) . "] for permission [{$permId}]. Allowed: " . implode(', ', $capAccess)
                );
            }
        }
    }

    public function getAccessibleAccounts($user)
    {
        if (!$user || !isset($user->id)) {
            return collect();
        }

        if ($this->isSystemZeus($user)) {
            return \AbacPermissions\Models\Account::all();
        }

        $rolesTable = config('abacpermissions.tables.roles', 'roles');

        $roleAccountIds = DB::table('role_user')
            ->join($rolesTable, "{$rolesTable}.id", '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->whereNotNull("{$rolesTable}.account_id")
            ->pluck("{$rolesTable}.account_id");

        $directAccountIds = \AbacPermissions\Models\AssignedPermission::query()
            ->where('assignee_type', 'user')
            ->where('assignee_id', $user->id)
            ->whereNotNull('account_id')
            ->pluck('account_id');

        $allAccountIds = $roleAccountIds->merge($directAccountIds)->unique();

        return \AbacPermissions\Models\Account::whereIn('id', $allAccountIds)->get();
    }

    /**
     * Clear cache for a user.
     */
    public function flushCache($user, $accountId = null)
    {
        if ($user && method_exists($user, 'clearPermissionCache')) {
            $user->clearPermissionCache();
        }

        // Ideally clear global AND tenant specific.
        // For simplicity, we might iterate or use tags if supported.
        // Without tags, we can't easily clear "all contexts" unless we know them.
        // We will just clear the specific key if known, or assume the user logs out/cache expires.
        // Or better: Use Cache Tags if available: ['abac_user_{id}'].

        $this->bumpUserVersion($user->id);

        if ($this->supportsTags()) {
            Cache::tags($this->userCacheTags($user->id))->flush();
            return;
        }

        // Fallback: clear keys for the currently known contexts.
        Cache::forget($this->makeCacheKey($user->id, $accountId));
        Cache::forget($this->makeCacheKey($user->id, null));
    }

    protected function resolvePermissions($user, $accountId): array
    {
        $allPermissions = [];
        $isTenant = !is_null($accountId);
        $roleIds = $this->getApplicableRoleIds($user->id, $accountId);

        // 2. Check for Zeus
        // System Zeus: Role with zeus_level='system'
        if ($this->isSystemZeus($user)) {
            return ['*']; // Full bypass
        }

        // Tenant Zeus: Role with zeus_level='tenant' AND currently in that tenant
        if ($isTenant && $this->isTenantZeus($user, $accountId)) {
            return ['*']; // In this context, they have everything
        }

        // 3. Collect Permissions from Roles via AssignedPermission
        if ($roleIds->isNotEmpty()) {
            $roleAssignments = \AbacPermissions\Models\AssignedPermission::query()
                ->where('assignee_type', 'role')
                ->whereIn('assignee_id', $roleIds)
                ->with('permission')
                ->get();

            foreach ($roleAssignments as $assignment) {
                $allPermissions = array_merge($allPermissions, $assignment->getExpandedPermissions());
            }
        }

        // 4. Collect Direct User Permissions via AssignedPermission
        $userAssignments = \AbacPermissions\Models\AssignedPermission::query()
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

    protected function getApplicableRoleIds($userId, $accountId): \Illuminate\Support\Collection
    {
        $rolesTable = config('abacpermissions.tables.roles', 'roles');

        return DB::table('role_user')
            ->join($rolesTable, "{$rolesTable}.id", '=', 'role_user.role_id')
            ->where('role_user.user_id', $userId)
            ->where(function ($query) use ($rolesTable, $accountId) {
                $query->whereNull("{$rolesTable}.account_id");
                if ($accountId !== null) {
                    $query->orWhere("{$rolesTable}.account_id", $accountId);
                }
            })
            ->pluck("{$rolesTable}.id");
    }

    protected function supportsTags(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }

    protected function makeCacheKey(string $userId, $accountId = null): string
    {
        $globalVersion = Cache::get('abacpermissions_version', 1);
        $userVersion = Cache::get($this->userVersionKey($userId), 1);
        $prefix = config('abacpermissions.cache.key_prefix', 'abacpermissions_');
        $scope = $accountId ?? 'global';

        return "{$prefix}{$globalVersion}_u{$userVersion}_perms_{$userId}_{$scope}";
    }

    protected function userCacheTags(string $userId): array
    {
        return ["abacpermissions_user_{$userId}"];
    }

    protected function userVersionKey(string $userId): string
    {
        return "abacpermissions_user_version_{$userId}";
    }

    protected function bumpUserVersion(string $userId): void
    {
        $key = $this->userVersionKey($userId);

        if (!Cache::has($key)) {
            Cache::forever($key, 2);
            return;
        }

        Cache::increment($key);
    }
}
