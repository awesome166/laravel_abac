<?php

namespace AbacPermissions\Observers;

use AbacPermissions\Cache\PermissionCacheInvalidator;
use AbacPermissions\Models\Permission;

class PermissionObserver
{
    public function __construct(
        protected PermissionCacheInvalidator $invalidator
    ) {}

    public function created(Permission $permission): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function updated(Permission $permission): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function deleted(Permission $permission): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function restored(Permission $permission): void
    {
        $this->invalidator->invalidateGlobal();
    }

    public function forceDeleted(Permission $permission): void
    {
        $this->invalidator->invalidateGlobal();
    }
}
