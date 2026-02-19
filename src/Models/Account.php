<?php

namespace AbacPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Collection;

class Account extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Ensure Eloquent's polymorphic system stores/matches 'account'
     * (not the fully-qualified class name) in the assignee_type column.
     */
    public function getMorphClass()
    {
        return 'account';
    }

    public function getUsersTable()
    {
        return config('abacpermissions.tables.accounts', 'accounts');
    }

    public function users()
    {
        return $this->belongsToMany(
            config('abacpermissions.models.user'),
            config('abacpermissions.tables.account_user', 'account_user'),
            'account_id',
            'user_id'
        );
    }

    /**
     * All permissions assigned directly to this account.
     * Grantable ones define the cap that members may delegate.
     */
    public function assignedPermissions()
    {
        return $this->morphMany(
            config('abacpermissions.models.assigned_permission', AssignedPermission::class),
            'assignee'
        );
    }

    /**
     * Returns the account's delegatable permission cap as:
     *   [ permission_id => access[] ]
     *
     * This is the ceiling for what any user in this tenant can grant to others.
     * access = null means full access (applies to on-off and crud permissions).
     */
    public function getGrantableCap(): Collection
    {
        return $this->assignedPermissions()
            ->where('grantable', true)
            ->with('permission')
            ->get()
            ->keyBy('permission_id')
            ->map(fn ($ap) => $ap->access); // null = full access for that permission
    }
}
