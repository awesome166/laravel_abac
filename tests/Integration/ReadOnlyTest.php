<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Role;
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Facades\AbacPermissions;

class ReadOnlyTest extends TestCase
{
    /** @test */
    public function it_demonstrates_read_only_vs_full_access()
    {
        // 1. Setup Permissions
        // "posts" is a CRUD bundle
        $postsPerm = Permission::create(['name' => 'posts', 'type' => 'crud']);

        // "posts:read" is a specific granular permission
        $postsReadPerm = Permission::create(['name' => 'posts:read', 'type' => 'on-off']);

        // 2. Setup Users
        $admin = TestUser::create(['email' => 'admin@test.com']);
        $viewer = TestUser::create(['email' => 'viewer@test.com']);

        // 3. Setup Roles
        $adminRole = Role::create(['name' => 'Admin']);
        $viewerRole = Role::create(['name' => 'Viewer']);

        // Admin gets the CRUD bundle (full access)
        AssignedPermission::create([
            'permission_id' => $postsPerm->id,
            'assignee_id' => $adminRole->id,
            'assignee_type' => 'role',
            'account_id' => null,
        ]);

        // Viewer gets ONLY the read permission
        AssignedPermission::create([
            'permission_id' => $postsReadPerm->id,
            'assignee_id' => $viewerRole->id,
            'assignee_type' => 'role',
            'account_id' => null,
        ]);

        $admin->roles()->attach($adminRole);
        $viewer->roles()->attach($viewerRole);

        // 4. Assertions

        // Admin has everything
        $this->assertTrue(AbacPermissions::hasPermission($admin, 'posts:create'));
        $this->assertTrue(AbacPermissions::hasPermission($admin, 'posts:read'));
        $this->assertTrue(AbacPermissions::hasPermission($admin, 'posts:delete'));

        // Viewer has ONLY read
        $this->assertTrue(AbacPermissions::hasPermission($viewer, 'posts:read'));

        $this->assertFalse(AbacPermissions::hasPermission($viewer, 'posts:create'));
        $this->assertFalse(AbacPermissions::hasPermission($viewer, 'posts:delete'));
    }
}
