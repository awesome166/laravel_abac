<?php

namespace AbacPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignedPermission extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'access'    => 'array',
        'grantable' => 'boolean',
    ];



    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        return config('abacpermissions.tables.assigned_permissions', 'assigned_permissions');
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
            config('abacpermissions.models.permission', Permission::class),
            'permission_id'
        );
    }

    /**
     * Account scope (nullable for global assignments)
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(
            config('abacpermissions.models.account', Account::class),
            'account_id'
        );
    }

    /**
     * Scope: only grantable assignments (can be redistributed to other users).
     */
    public function scopeGrantable($query)
    {
        return $query->where('grantable', true);
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
        if ($assignee instanceof \AbacPermissions\Models\Role) {
            return 'role';
        }

        if (is_a($assignee, config('abacpermissions.models.user'))) {
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
     * Get the expanded permissions based on access restrictions.
     * Safe to call regardless of whether 'permission' was eager-loaded.
     */
    public function getExpandedPermissions(): array
    {
        // Defensive: load the permission relationship if it wasn't eager-loaded
        $this->loadMissing('permission');

        $permission = $this->permission;

        if (!$permission) {
            return [];
        }

        $access = $this->access; // correctly read from the cast attribute

        // Handle on-off type permissions
        if ($permission->type === 'on-off') {
            // No access specified → default to granted
            if (empty($access)) {
                return [$permission->name];
            }

            // Explicitly denied
            if (in_array('off', $access)) {
                return [];
            }

            // Explicitly granted or any other value → grant
            return [$permission->name];
        }

        // Handle CRUD type permissions
        if ($permission->type === 'crud') {
            // No access restrictions → full CRUD
            if (empty($access)) {
                return $permission->expand();
            }

            // Return only the allowed actions
            $expanded = [];
            foreach ($access as $action) {
                $expanded[] = "{$permission->name}:{$action}";
            }
            return $expanded;
        }

        // Fallback for any other type
        return $permission->expand();
    }
}

