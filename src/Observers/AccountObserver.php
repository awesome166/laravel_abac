<?php

namespace AbacPermissions\Observers;

use AbacPermissions\Cache\PermissionCacheInvalidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AccountObserver
{
    public function __construct(
        protected PermissionCacheInvalidator $invalidator
    ) {}

    public function updated(Model $account): void
    {
        $this->invalidateAccountUsers($account->id ?? null);
    }

    public function deleted(Model $account): void
    {
        $this->invalidateAccountUsers($account->id ?? null);
    }

    public function restored(Model $account): void
    {
        $this->invalidateAccountUsers($account->id ?? null);
    }

    public function forceDeleted(Model $account): void
    {
        $this->invalidateAccountUsers($account->id ?? null);
    }

    protected function invalidateAccountUsers($accountId): void
    {
        if (!$accountId) {
            $this->invalidator->invalidateGlobal();
            return;
        }

        $rolesTable = config('abacpermissions.tables.roles', 'roles');
        $accountUserTable = config('abacpermissions.tables.account_user', 'account_user');

        $membershipUsers = DB::table($accountUserTable)
            ->where('account_id', $accountId)
            ->pluck('user_id');

        $roleUsers = DB::table('role_user')
            ->join($rolesTable, "{$rolesTable}.id", '=', 'role_user.role_id')
            ->where("{$rolesTable}.account_id", $accountId)
            ->pluck('role_user.user_id');

        $affected = $membershipUsers->merge($roleUsers)->unique()->map(fn ($id) => (string) $id);
        $this->invalidator->invalidateUsers($affected);
    }
}

