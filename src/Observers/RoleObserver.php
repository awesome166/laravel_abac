<?php

namespace AbacPermissions\Observers;

use AbacPermissions\Cache\PermissionCacheInvalidator;
use AbacPermissions\Models\Role;

class RoleObserver
{
    public function __construct(
        protected PermissionCacheInvalidator $invalidator
    ) {}

    public function created(Role $role): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function updated(Role $role): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function deleted(Role $role): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function restored(Role $role): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function forceDeleted(Role $role): void
    {
        $this->invalidator->invalidateGlobal();
    }
}
