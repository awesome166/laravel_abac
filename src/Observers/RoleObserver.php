<?php

namespace AbacPermissions\Observers;

use AbacPermissions\Models\Role;
use Illuminate\Support\Facades\Cache;

class RoleObserver
{
    public function saved(Role $role)
    {
        Cache::increment('abacpermissions_version');
    }

    public function deleted(Role $role)
    {
        Cache::increment('abacpermissions_version');
    }
}
