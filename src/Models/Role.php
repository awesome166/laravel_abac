<?php

namespace AbacPermissions\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = [];

    /**
     * Assigned permissions (polymorphic relationship)
     */
    public function assignedPermissions()
    {
        return $this->morphMany(
            config('abacpermissions.models.assigned_permission', \AbacPermissions\Models\AssignedPermission::class),
            'assignee',
            'assignee_type',
            'assignee_id'
        );
    }

    /**
     * Get all permissions for this role (through assignments)
     */
    // public function permissions()
    // {
    //     return \AbacPermissions\Models\Permission::whereHas('assignments', function ($query) {
    //         $query->where('assignee_type', 'role')
    //             ->where('assignee_id', $this->id);
    //     });
    // }
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
     * Get permissions with their access restrictions
     */
    public function getPermissionsWithAccess()
    {
        return $this->assignedPermissions()->with('permission')->get();
    }

    public function isSystemZeus(): bool
    {
        return $this->zeus_level === 'system';
    }

    public function isTenantZeus(): bool
    {
        return $this->zeus_level === 'tenant';
    }
}
