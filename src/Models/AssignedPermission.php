<?php

namespace Awesome\Abac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignedPermission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'access' => 'array',
    ];

    /**
     * Get the table name from config
     */
    public function getTable()
    {
        return config('awesome-abac.tables.assigned_permissions', 'assigned_permissions');
    }

    /**
     * Polymorphic relationship to the assignee (User, Token, Role, etc.)
     */
    public function assignee(): MorphTo
    {
        return $this->morphTo('assignee', 'assignee_type', 'assignee_id');
    }

    /**
     * Permission being assigned
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(
            config('awesome-abac.models.permission', Permission::class),
            'permission_id'
        );
    }

    /**
     * Account scope (nullable for global assignments)
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(
            config('awesome-abac.models.account', Account::class),
            'account_id'
        );
    }

    /**
     * Scope to filter by assignee type
     */
    public function scopeForAssigneeType($query, string $type)
    {
        return $query->where('assignee_type', $type);
    }

    /**
     * Scope to filter by specific assignee
     */
    public function scopeForAssignee($query, $assignee)
    {
        return $query->where('assignee_id', $assignee->id)
            ->where('assignee_type', $this->getAssigneeType($assignee));
    }

    /**
     * Scope to filter by account
     */
    public function scopeForAccount($query, $accountId = null)
    {
        if ($accountId === null) {
            return $query->whereNull('account_id');
        }

        return $query->where(function ($q) use ($accountId) {
            $q->whereNull('account_id')->orWhere('account_id', $accountId);
        });
    }

    /**
     * Get the assignee type string from a model instance
     */
    protected function getAssigneeType($assignee): string
    {
        if ($assignee instanceof \Awesome\Abac\Models\Role) {
            return 'role';
        }

        if (is_a($assignee, config('awesome-abac.models.user'))) {
            return 'user';
        }

        // Future: support for tokens
        return 'user';
    }

    /**
     * Check if this assignment has specific access restrictions
     */
    public function hasAccessRestrictions(): bool
    {
        return !empty($this->access);
    }

    /**
     * Get the expanded permissions based on access restrictions
     */
    public function getExpandedPermissions(): array
    {
        $permission = $this->permission;

        if (!$permission) {
            return [];
        }

        // Handle on-off type permissions
        if ($permission->type === 'on-off') {
            // If no access specified, default to 'on' (granted)
            if (!$this->hasAccessRestrictions()) {
                return [$permission->name];
            }

            // Check if access is explicitly set to 'off' (denied)
            if (in_array('off', $this->access)) {
                return []; // Permission denied, return empty
            }

            // If access contains 'on', grant the permission
            if (in_array('on', $this->access)) {
                return [$permission->name];
            }

            // Default to granted if access array exists but doesn't contain 'off'
            return [$permission->name];
        }

        // Handle CRUD type permissions
        if ($permission->type === 'crud') {
            // If no access restrictions, return full CRUD
            if (!$this->hasAccessRestrictions()) {
                return $permission->expand();
            }

            // Return only the allowed actions
            $expanded = [];
            foreach ($this->access as $action) {
                $expanded[] = "{$permission->name}:{$action}";
            }

            return $expanded;
        }

        // Fallback for any other type
        return $permission->expand();
    }
}
