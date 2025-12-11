<?php

namespace Awesome\Abac\Observers;

use Awesome\Abac\Models\Role;
use Illuminate\Support\Facades\Cache;

class RoleObserver
{
    public function saved(Role $role)
    {
        Cache::increment('awesome_abac_version');
    }

    public function deleted(Role $role)
    {
        Cache::increment('awesome_abac_version');
    }
}
