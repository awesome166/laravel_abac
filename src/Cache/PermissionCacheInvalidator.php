<?php

namespace AbacPermissions\Cache;

use Illuminate\Support\Facades\Cache;

class PermissionCacheInvalidator
{
    public function invalidateGlobal(): void
    {
        $current = (int) Cache::get('abacpermissions_version', 1);
        Cache::forever('abacpermissions_version', $current + 1);
    }

    public function invalidateUsers(iterable $userIds = []): void
    {
        foreach ($userIds as $userId) {
            if ($userId === null || $userId === '') {
                continue;
            }

            $this->clearRequestCacheForUser((string) $userId);
            $this->bumpUserVersion((string) $userId);

            if ($this->supportsTags()) {
                Cache::tags(["abacpermissions_user_{$userId}"])->flush();
            }
        }

        $this->invalidateGlobal();
    }

    public function clearRequestCacheForUser($userId): void
    {
        if (!app()->bound('request')) {
            return;
        }

        $request = request();
        $userId = (string) $userId;

        $permissionRegistry = "abacpermissions_user_permission_keys_{$userId}";
        $tenantRegistry = "abacpermissions_tenant_zeus_keys_{$userId}";

        $permissionKeys = (array) $request->attributes->get($permissionRegistry, []);
        foreach ($permissionKeys as $key) {
            $request->attributes->remove($key);
        }
        $request->attributes->remove($permissionRegistry);

        $request->attributes->remove('is_system_zeus_' . $userId);

        $tenantKeys = (array) $request->attributes->get($tenantRegistry, []);
        foreach ($tenantKeys as $key) {
            $request->attributes->remove($key);
        }
        $request->attributes->remove($tenantRegistry);
    }

    protected function bumpUserVersion(string $userId): void
    {
        $key = "abacpermissions_user_version_{$userId}";
        $current = (int) Cache::get($key, 1);
        Cache::forever($key, $current + 1);
    }

    protected function supportsTags(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }
}

