<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Models\AssignedPermission;
use Awesome\Abac\Facades\Abac;

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
        $this->assertTrue(Abac::hasPermission($activeUser, 'view.dashboard'));

        // User with 'off' access should NOT have permission
        $this->assertFalse(Abac::hasPermission($deniedUser, 'view.dashboard'));

        // User with no access specified should have permission (defaults to 'on')
        $this->assertTrue(Abac::hasPermission($defaultUser, 'view.dashboard'));
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

        $this->assertTrue(Abac::hasPermission($user, 'feature.beta'));

        // Toggle to 'off'
        $assignment->update(['access' => ['off']]);

        // Clear cache by incrementing version
        \Illuminate\Support\Facades\Cache::increment('awesome_abac_version');

        $this->assertFalse(Abac::hasPermission($user, 'feature.beta'));

        // Toggle back to 'on'
        $assignment->update(['access' => ['on']]);

        // Clear cache again
        \Illuminate\Support\Facades\Cache::increment('awesome_abac_version');

        $this->assertTrue(Abac::hasPermission($user, 'feature.beta'));
    }
}
