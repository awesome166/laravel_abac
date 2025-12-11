<?php

namespace Awesome\Abac\Controllers;

use Awesome\Abac\Facades\Abac;

trait AbacControllerHelper
{
    public function authorizePermission(string $permission)
    {
        if (!Abac::hasPermission(auth()->user(), $permission)) {
            abort(403, "Unauthorized: Missing permission '$permission'");
        }
    }

    public function assignRole($user, $role)
    {
        // Wrapper for adding role
        $user->roles()->attach($role);
        Abac::flushCache($user);
    }

    // ==================== PERMISSION CRUD ====================

    /**
     * Create a new permission
     */
    public function createPermission(array $data)
    {
        $permission = \Awesome\Abac\Models\Permission::create([
            'name' => $data['name'],
            'type' => $data['type'] ?? 'on', // 'on' or 'crud'
            'description' => $data['description'] ?? null,
            'account_id' => $data['account_id'] ?? null,
        ]);

        $this->recachePermissionsList();
        return $permission;
    }

    /**
     * Update an existing permission
     */
    public function updatePermission($permissionId, array $data)
    {
        $permission = \Awesome\Abac\Models\Permission::findOrFail($permissionId);
        $permission->update($data);

        $this->recachePermissionsList();
        $this->flushAffectedUsers($permission);

        return $permission;
    }

    /**
     * Delete a permission
     */
    public function deletePermission($permissionId)
    {
        $permission = \Awesome\Abac\Models\Permission::findOrFail($permissionId);

        // Detach from all roles and users
        $permission->roles()->detach();
        $permission->delete();

        $this->recachePermissionsList();

        return true;
    }

    /**
     * Get a permission by ID
     */
    public function getPermission($permissionId)
    {
        return \Awesome\Abac\Models\Permission::with(['roles'])->findOrFail($permissionId);
    }

    // ==================== ROLE CRUD ====================

    /**
     * Create a new role
     */
    public function createRole(array $data)
    {
        $role = \Awesome\Abac\Models\Role::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'zeus_level' => $data['zeus_level'] ?? null, // null, 'system', or 'tenant'
            'account_id' => $data['account_id'] ?? null,
        ]);

        return $role;
    }

    /**
     * Update an existing role
     */
    public function updateRole($roleId, array $data)
    {
        $role = \Awesome\Abac\Models\Role::findOrFail($roleId);
        $role->update($data);

        $this->flushAffectedUsersForRole($role);

        return $role;
    }

    /**
     * Delete a role
     */
    public function deleteRole($roleId)
    {
        $role = \Awesome\Abac\Models\Role::findOrFail($roleId);

        // Detach from all users and permissions
        $role->permissions()->detach();
        $role->delete();

        return true;
    }

    /**
     * Get a role by ID
     */
    public function getRole($roleId)
    {
        return \Awesome\Abac\Models\Role::with(['permissions'])->findOrFail($roleId);
    }

    // ==================== ATTACH PERMISSIONS TO ROLES ====================

    /**
     * Attach a permission to a role
     */
    public function attachPermissionToRole($roleId, $permissionId, array $access = null)
    {
        $role = \Awesome\Abac\Models\Role::findOrFail($roleId);
        $permission = \Awesome\Abac\Models\Permission::findOrFail($permissionId);

        // Create or update assignment
        \Awesome\Abac\Models\AssignedPermission::updateOrCreate(
            [
                'permission_id' => $permissionId,
                'assignee_id' => $roleId,
                'assignee_type' => 'role',
                'account_id' => null,
            ],
            [
                'access' => ($permission->type === 'crud' && !empty($access)) ? $access : null,
            ]
        );

        $this->flushAffectedUsersForRole($role);

        return true;
    }

    /**
     * Attach multiple permissions to a role
     */
    public function attachPermissionsToRole($roleId, array $permissions)
    {
        $role = \Awesome\Abac\Models\Role::findOrFail($roleId);

        foreach ($permissions as $permissionData) {
            $permissionId = is_array($permissionData) ? $permissionData['id'] : $permissionData;
            $access = is_array($permissionData) ? ($permissionData['access'] ?? null) : null;

            $permission = \Awesome\Abac\Models\Permission::findOrFail($permissionId);

            \Awesome\Abac\Models\AssignedPermission::updateOrCreate(
                [
                    'permission_id' => $permissionId,
                    'assignee_id' => $roleId,
                    'assignee_type' => 'role',
                    'account_id' => null,
                ],
                [
                    'access' => ($permission->type === 'crud' && !empty($access)) ? $access : null,
                ]
            );
        }

        $this->flushAffectedUsersForRole($role);

        return true;
    }

    /**
     * Detach a permission from a role
     */
    public function detachPermissionFromRole($roleId, $permissionId)
    {
        $role = \Awesome\Abac\Models\Role::findOrFail($roleId);

        \Awesome\Abac\Models\AssignedPermission::where('permission_id', $permissionId)
            ->where('assignee_id', $roleId)
            ->where('assignee_type', 'role')
            ->delete();

        $this->flushAffectedUsersForRole($role);

        return true;
    }

    /**
     * Detach all permissions from a role
     */
    public function detachAllPermissionsFromRole($roleId)
    {
        $role = \Awesome\Abac\Models\Role::findOrFail($roleId);

        \Awesome\Abac\Models\AssignedPermission::where('assignee_id', $roleId)
            ->where('assignee_type', 'role')
            ->delete();

        $this->flushAffectedUsersForRole($role);

        return true;
    }

    // ==================== ATTACH PERMISSIONS TO USERS ====================

    /**
     * Attach a permission directly to a user
     */
    public function attachPermissionToUser($user, $permissionId, $accountId = null, array $access = null)
    {
        $permission = \Awesome\Abac\Models\Permission::findOrFail($permissionId);

        \Awesome\Abac\Models\AssignedPermission::updateOrCreate(
            [
                'permission_id' => $permissionId,
                'assignee_id' => $user->id,
                'assignee_type' => 'user',
                'account_id' => $accountId,
            ],
            [
                'access' => ($permission->type === 'crud' && !empty($access)) ? $access : null,
            ]
        );

        Abac::flushCache($user, $accountId);

        return true;
    }

    /**
     * Attach multiple permissions to a user
     */
    public function attachPermissionsToUser($user, array $permissions, $accountId = null)
    {
        foreach ($permissions as $permissionData) {
            $permissionId = is_array($permissionData) ? $permissionData['id'] : $permissionData;
            $access = is_array($permissionData) ? ($permissionData['access'] ?? null) : null;

            $permission = \Awesome\Abac\Models\Permission::findOrFail($permissionId);

            \Awesome\Abac\Models\AssignedPermission::updateOrCreate(
                [
                    'permission_id' => $permissionId,
                    'assignee_id' => $user->id,
                    'assignee_type' => 'user',
                    'account_id' => $accountId,
                ],
                [
                    'access' => ($permission->type === 'crud' && !empty($access)) ? $access : null,
                ]
            );
        }

        Abac::flushCache($user, $accountId);

        return true;
    }

    /**
     * Detach a permission from a user
     */
    public function detachPermissionFromUser($user, $permissionId, $accountId = null)
    {
        $query = \Awesome\Abac\Models\AssignedPermission::where('permission_id', $permissionId)
            ->where('assignee_id', $user->id)
            ->where('assignee_type', 'user');

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        $query->delete();

        Abac::flushCache($user, $accountId);

        return true;
    }

    /**
     * Detach all permissions from a user
     */
    public function detachAllPermissionsFromUser($user, $accountId = null)
    {
        $query = \Awesome\Abac\Models\AssignedPermission::where('assignee_id', $user->id)
            ->where('assignee_type', 'user');

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        $query->delete();

        Abac::flushCache($user, $accountId);

        return true;
    }

    // ==================== ATTACH ROLES TO USERS ====================

    /**
     * Detach a role from a user
     */
    public function detachRole($user, $role)
    {
        $user->roles()->detach($role);
        Abac::flushCache($user);

        return true;
    }

    /**
     * Sync roles for a user (replaces all existing roles)
     */
    public function syncRoles($user, array $roleIds)
    {
        $user->roles()->sync($roleIds);
        Abac::flushCache($user);

        return true;
    }

    // ==================== PERMISSION LIST CACHING ====================

    /**
     * Get cached list of all permissions (e.g., ['account.create', 'account.read', ...])
     */
    public function getCachedPermissionsList($accountId = null)
    {
        $cacheKey = 'awesome_abac_permissions_list_' . ($accountId ?? 'global');

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, config('awesome-abac.cache.ttl', 3600), function () use ($accountId) {
            $query = \Awesome\Abac\Models\Permission::query();

            if ($accountId) {
                $query->where(function ($q) use ($accountId) {
                    $q->whereNull('account_id')->orWhere('account_id', $accountId);
                });
            } else {
                $query->whereNull('account_id');
            }

            $permissions = $query->get();
            $list = [];

            foreach ($permissions as $permission) {
                $list = array_merge($list, $permission->expand());
            }

            return array_unique($list);
        });
    }

    /**
     * Recache the permissions list (call after creating/updating/deleting permissions)
     */
    protected function recachePermissionsList()
    {
        // Clear all permission list caches
        \Illuminate\Support\Facades\Cache::forget('awesome_abac_permissions_list_global');

        // Also increment version to invalidate all user permission caches
        $version = \Illuminate\Support\Facades\Cache::get('awesome_abac_version', 1);
        \Illuminate\Support\Facades\Cache::put('awesome_abac_version', $version + 1, now()->addYear());
    }

    /**
     * Flush cache for all users affected by a permission change
     */
    protected function flushAffectedUsers($permission)
    {
        // Get all users with this permission (directly or via roles)
        $affectedUserIds = collect();

        // Users with direct permission
        $directUsers = \Awesome\Abac\Models\AssignedPermission::where('permission_id', $permission->id)
            ->where('assignee_type', 'user')
            ->pluck('assignee_id');

        $affectedUserIds = $affectedUserIds->merge($directUsers);

        // Users with roles that have this permission
        $roleIds = \Awesome\Abac\Models\AssignedPermission::where('permission_id', $permission->id)
            ->where('assignee_type', 'role')
            ->pluck('assignee_id');

        if ($roleIds->isNotEmpty()) {
            $roleUsers = \Illuminate\Support\Facades\DB::table('role_user')
                ->whereIn('role_id', $roleIds)
                ->pluck('user_id');

            $affectedUserIds = $affectedUserIds->merge($roleUsers);
        }

        // Flush cache for each affected user
        $affectedUserIds->unique()->each(function ($userId) {
            $user = (object)['id' => $userId];
            Abac::flushCache($user);
        });
    }

    /**
     * Flush cache for all users with a specific role
     */
    protected function flushAffectedUsersForRole($role)
    {
        $userIds = \Illuminate\Support\Facades\DB::table('role_user')
            ->where('role_id', $role->id)
            ->pluck('user_id');

        $userIds->unique()->each(function ($userId) {
            $user = (object)['id' => $userId];
            Abac::flushCache($user);
        });
    }
}
