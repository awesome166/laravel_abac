<?php

namespace Awesome\Abac\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $guarded = [];

    /**
     * All assignments of this permission
     */
    public function assignments()
    {
        return $this->hasMany(
            config('awesome-abac.models.assigned_permission', AssignedPermission::class),
            'permission_id'
        );
    }

    /**
     * Get roles that have this permission assigned
     */
    public function assignedToRoles()
    {
        return $this->assignments()
            ->where('assignee_type', 'role')
            ->with('assignee');
    }

    /**
     * Get users that have this permission directly assigned
     */
    public function assignedToUsers()
    {
        return $this->assignments()
            ->where('assignee_type', 'user')
            ->with('assignee');
    }

    /**
     * Legacy accessor for roles (for backward compatibility in queries)
     */
    public function roles()
    {
        return Role::whereHas('assignedPermissions', function ($query) {
            $query->where('permission_id', $this->id);
        });
    }

    /**
     * Expand this permission if it is a CRUD type.
     * returns ['post:create', 'post:read', ...]
     */
    public function expand(): array
    {
        if ($this->type !== 'crud') {
            return [$this->name];
        }

        // Assume name is "subjects" -> "subjects:create", etc.
        // OR "subjects" -> "create_subjects" ?
        // Prompt examples often use "verb_subject".
        // "When type is "crud", the system expands it into create, read, update, delete actions"
        // Let's assume colon format `resource:action` or `action_resource`.
        // Let's use `resource:action` as it's cleaner for namespacing.
        // Or standard Larave `action resource`.
        // I will implement `resource:create`, `resource:read`...

        $actions = ['create', 'read', 'update', 'delete'];
        $expanded = [];
        foreach ($actions as $action) {
            $expanded[] = "{$this->name}:{$action}";
        }
        return $expanded;
    }
}
