<?php

namespace Awesome\Abac\Models;

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
            config('awesome-abac.models.assigned_permission', \Awesome\Abac\Models\AssignedPermission::class),
            'assignee',
            'assignee_type',
            'assignee_id'
        );
    }

    /**
     * Get all permissions for this role (through assignments)
     */
    public function permissions()
    {
        return \Awesome\Abac\Models\Permission::whereHas('assignments', function ($query) {
            $query->where('assignee_type', 'role')
                ->where('assignee_id', $this->id);
        });
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
