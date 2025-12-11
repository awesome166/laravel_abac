<?php

namespace Awesome\Abac\Observers;

use Awesome\Abac\Models\Permission;
use Illuminate\Support\Facades\Cache;

class PermissionObserver
{
    public function saved(Permission $permission)
    {
        $this->flush();
    }

    public function deleted(Permission $permission)
    {
        $this->flush();
    }

    protected function flush()
    {
        // Flush global ABAC cache because permissions definitions changed
        // Real implementation should be tag based
        // Cache::tags('abac')->flush();
        // Fallback: we can't easily clear all user keys prefix-based in standard drivers (file/redis yes, memcached no).
        // Let's increment a version key if we use a prefix?
        // Or assume shorter TTL.
        // For this package, we'll assume standard Cache::flush() in dev, or Log a warning.
        // Or better: clear just the affected keys if we track them. We don't.

        // Let's try to clear a specific "abac_version" key that prefixes all keys?
        Cache::increment('awesome_abac_version');
    }
}
