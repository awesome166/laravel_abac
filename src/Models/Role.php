<?php

namespace AbacPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Role extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $with = ['assignedPermissions'];
    protected $hidden = [
        'created_at',
        'updated_at',
    ];


    public function assignedPermissions()
    {
        return $this->morphMany(AssignedPermission::class, 'assignee');
    }


    public function getMorphClass()
    {
        return 'role';
    }

 //////

    // public function assignedPermissions() {
    //     return $this->morphMany(
    //         config('abacpermissions.models.assigned_permission', \AbacPermissions\Models\AssignedPermission::class),
    //         'assignee'
    //     );
    // }
    public function permissions(): \Illuminate\Database\Eloquent\Relations\MorphToMany {
        return $this->morphToMany(
            config('abacpermissions.models.permission', \AbacPermissions\Models\Permission::class),
            'assignee',
            'assigned_permissions',
            'assignee_id',
            'permission_id'
        )->using(config('abacpermissions.models.assigned_permission', \AbacPermissions\Models\AssignedPermission::class));
    }

    public function scopeGetPermissionsWithAccess($query) {
        return $query->with(['assignedPermissions.permission']);
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
