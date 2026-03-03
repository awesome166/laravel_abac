<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Facades\AbacPermissions;
use AbacPermissions\Models\Account;
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Role;
use AbacPermissions\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class CacheInvalidationTest extends TestCase
{
    /** @test */
    public function role_permission_update_invalidates_user_cache()
    {
        $permission = Permission::create(['name' => 'reports.view', 'type' => 'on-off']);
        $role = Role::create(['name' => 'Reporter']);
        $user = TestUser::create(['email' => 'role-update@test.com']);
        $user->roles()->attach($role->id);

        $assignment = AssignedPermission::create([
            'permission_id' => $permission->id,
            'assignee_type' => 'role',
            'assignee_id' => $role->id,
            'access' => ['on'],
        ]);

        $this->assertTrue(AbacPermissions::hasPermission($user, 'reports.view'));

        $assignment->update(['access' => ['off']]);

        $this->assertFalse(AbacPermissions::hasPermission($user, 'reports.view'));
    }

    /** @test */
    public function account_permission_update_invalidates_affected_users()
    {
        $account = Account::create(['name' => 'Acme', 'slug' => 'acme-cache']);
        $user = TestUser::create(['email' => 'account-update@test.com']);
        $user->accounts()->attach($account->id);

        Cache::forever('abacpermissions_version', 1);
        $this->assertSame(1, Cache::get('abacpermissions_version'));

        $permission = Permission::create(['name' => 'billing.export', 'type' => 'on-off']);
        AssignedPermission::create([
            'permission_id' => $permission->id,
            'assignee_type' => 'account',
            'assignee_id' => $account->id,
            'account_id' => null,
            'access' => ['on'],
            'grantable' => true,
        ]);

        $this->assertGreaterThan(1, Cache::get('abacpermissions_version'));
    }

    /** @test */
    public function user_direct_permission_update_invalidates_immediately()
    {
        $permission = Permission::create(['name' => 'settings.manage', 'type' => 'on-off']);
        $user = TestUser::create(['email' => 'direct-update@test.com']);

        $assignment = AssignedPermission::create([
            'permission_id' => $permission->id,
            'assignee_type' => 'user',
            'assignee_id' => $user->id,
            'access' => ['on'],
        ]);

        $this->assertTrue(AbacPermissions::hasPermission($user, 'settings.manage'));

        $assignment->update(['access' => ['off']]);

        $this->assertFalse(AbacPermissions::hasPermission($user, 'settings.manage'));
    }

    /** @test */
    public function role_sync_invalidates_permission_cache()
    {
        $permission = Permission::create(['name' => 'posts', 'type' => 'crud']);
        $roleWithPermission = Role::create(['name' => 'With Permission']);
        $roleWithoutPermission = Role::create(['name' => 'Without Permission']);
        $user = TestUser::create(['email' => 'sync@test.com']);

        AssignedPermission::create([
            'permission_id' => $permission->id,
            'assignee_type' => 'role',
            'assignee_id' => $roleWithPermission->id,
            'access' => ['read'],
        ]);

        $user->roles()->sync([$roleWithPermission->id]);
        $this->assertTrue(AbacPermissions::hasPermission($user, 'posts:read'));

        $user->roles()->sync([$roleWithoutPermission->id]);
        $this->assertFalse(AbacPermissions::hasPermission($user, 'posts:read'));
    }

    /** @test */
    public function zeus_user_always_passes_and_payload_contains_wildcard()
    {
        $user = TestUser::create(['email' => 'zeus-payload@test.com']);
        $role = Role::create(['name' => 'System Zeus', 'zeus_level' => 'system']);
        $user->roles()->attach($role->id);

        $this->assertTrue(AbacPermissions::hasPermission($user, 'anything.random'));

        $payload = AbacPermissions::getFrontendAuthPayload($user);

        $this->assertTrue($payload['is_zeus']);
        $this->assertTrue($payload['is_system_zeus']);
        $this->assertContains('*', $payload['permissions']);
    }
}
