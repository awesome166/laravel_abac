<?php

namespace AbacPermissions\Observers;

use AbacPermissions\Cache\PermissionCacheInvalidator;
use AbacPermissions\Models\AssignedPermission;
use Illuminate\Support\Facades\DB;

class AssignedPermissionObserver
{
    public function __construct(
        protected PermissionCacheInvalidator $invalidator
    ) {}

    public function created(AssignedPermission $assignment): void
    {
        $this->flushAffectedUsers($assignment);
    }

    public function updated(AssignedPermission $assignment): void
    {
        $this->flushAffectedUsers($assignment);
    }

    public function deleted(AssignedPermission $assignment): void
    {
        $this->flushAffectedUsers($assignment);
    }

    public function restored(AssignedPermission $assignment): void
    {
        $this->flushAffectedUsers($assignment);
    }

    public function forceDeleted(AssignedPermission $assignment): void
    {
        $this->flushAffectedUsers($assignment);
    }

    protected function flushAffectedUsers(AssignedPermission $assignment): void
    {
        $userIds = [];

        if ($assignment->assignee_type === 'user') {
            $userIds[] = (string) $assignment->assignee_id;
        }

        if ($assignment->assignee_type === 'role') {
            $userIds = DB::table('role_user')
                ->where('role_id', $assignment->assignee_id)
                ->pluck('user_id');
            $userIds = $userIds->map(fn ($id) => (string) $id)->all();
        }

        if ($assignment->assignee_type === 'account') {
            $rolesTable = config('abacpermissions.tables.roles', 'roles');
            $accountUserTable = config('abacpermissions.tables.account_user', 'account_user');

            $membershipUsers = DB::table($accountUserTable)
                ->where('account_id', $assignment->assignee_id)
                ->pluck('user_id');

            $roleUsers = DB::table('role_user')
                ->join($rolesTable, "{$rolesTable}.id", '=', 'role_user.role_id')
                ->where("{$rolesTable}.account_id", $assignment->assignee_id)
                ->pluck('role_user.user_id');

            $affected = $membershipUsers->merge($roleUsers)->unique();
            $userIds = array_merge($userIds, $affected->map(fn ($id) => (string) $id)->all());
        }

        $this->invalidator->invalidateUsers($userIds);
    }
}
