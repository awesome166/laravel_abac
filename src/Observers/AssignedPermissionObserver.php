<?php

namespace AbacPermissions\Observers;

use AbacPermissions\Facades\AbacPermissions;
use AbacPermissions\Models\AssignedPermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AssignedPermissionObserver
{
    public function saved(AssignedPermission $assignment): void
    {
        $this->flushAffectedUsers($assignment);
    }

    public function deleted(AssignedPermission $assignment): void
    {
        $this->flushAffectedUsers($assignment);
    }

    protected function flushAffectedUsers(AssignedPermission $assignment): void
    {
        Cache::increment('abacpermissions_version');

        if ($assignment->assignee_type === 'user') {
            AbacPermissions::flushCache((object) ['id' => $assignment->assignee_id], $assignment->account_id);
            return;
        }

        if ($assignment->assignee_type === 'role') {
            $userIds = DB::table('role_user')
                ->where('role_id', $assignment->assignee_id)
                ->pluck('user_id');

            foreach ($userIds as $userId) {
                AbacPermissions::flushCache((object) ['id' => $userId], $assignment->account_id);
            }
            return;
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

            foreach ($affected as $userId) {
                AbacPermissions::flushCache((object) ['id' => $userId], $assignment->assignee_id);
            }
        }
    }
}

