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
}
