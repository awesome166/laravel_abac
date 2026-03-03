<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Role;
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Facades\AbacPermissions;

class OnOffAccessTest extends TestCase
{
    /** @test */
    public function it_handles_on_off_permission_access()
    {
        // Create an on-off permission
        $dashboardPerm = Permission::create([
            'name' => 'view.dashboard',
            'type' => 'on-off'
        ]);

        // Create roles
        $activeRole = Role::create(['name' => 'Active User']);
        $deniedRole = Role::create(['name' => 'Denied User']);
        $defaultRole = Role::create(['name' => 'Default User']);

        // Assign permission with 'on' access (explicitly granted)
        AssignedPermission::create([
            'permission_id' => $dashboardPerm->id,
            'assignee_id' => $activeRole->id,
            'assignee_type' => 'role',
            'account_id' => null,
            'access' => ['on'],
        ]);

        // Assign permission with 'off' access (explicitly denied)
        AssignedPermission::create([
            'permission_id' => $dashboardPerm->id,
            'assignee_id' => $deniedRole->id,
            'assignee_type' => 'role',
            'account_id' => null,
            'access' => ['off'],
        ]);

        // Assign permission with no access specified (defaults to 'on')
        AssignedPermission::create([
            'permission_id' => $dashboardPerm->id,
            'assignee_id' => $defaultRole->id,
            'assignee_type' => 'role',
            'account_id' => null,
        ]);

        // Create users
        $activeUser = TestUser::create(['email' => 'active@test.com']);
        $deniedUser = TestUser::create(['email' => 'denied@test.com']);
        $defaultUser = TestUser::create(['email' => 'default@test.com']);

        $activeUser->roles()->attach($activeRole);
        $deniedUser->roles()->attach($deniedRole);
        $defaultUser->roles()->attach($defaultRole);

        // Assertions
        // User with 'on' access should have permission
        $this->assertTrue(AbacPermissions::hasPermission($activeUser, 'view.dashboard'));

        // User with 'off' access should NOT have permission
        $this->assertFalse(AbacPermissions::hasPermission($deniedUser, 'view.dashboard'));

        // User with no access specified should have permission (defaults to 'on')
        $this->assertTrue(AbacPermissions::hasPermission($defaultUser, 'view.dashboard'));
    }

    /** @test */
    public function it_allows_toggling_on_off_permissions()
    {
        $permission = Permission::create([
            'name' => 'feature.beta',
            'type' => 'on-off'
        ]);

        $user = TestUser::create(['email' => 'toggle@test.com']);

        // Initially grant permission with 'on'
        $assignment = AssignedPermission::create([
            'permission_id' => $permission->id,
            'assignee_id' => $user->id,
            'assignee_type' => 'user',
            'account_id' => null,
            'access' => ['on'],
        ]);

        $this->assertTrue(AbacPermissions::hasPermission($user, 'feature.beta'));

        // Toggle to 'off'
        $assignment->update(['access' => ['off']]);

        // Clear cache through package invalidator
        AbacPermissions::invalidateCache([$user->id]);

        $this->assertFalse(AbacPermissions::hasPermission($user, 'feature.beta'));

        // Toggle back to 'on'
        $assignment->update(['access' => ['on']]);

        // Clear cache again
        AbacPermissions::invalidateCache([$user->id]);

        $this->assertTrue(AbacPermissions::hasPermission($user, 'feature.beta'));
    }
}
