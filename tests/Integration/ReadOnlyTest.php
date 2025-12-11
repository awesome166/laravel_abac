<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Models\AssignedPermission;
use Awesome\Abac\Facades\Abac;

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
        $this->assertTrue(Abac::hasPermission($admin, 'posts:create'));
        $this->assertTrue(Abac::hasPermission($admin, 'posts:read'));
        $this->assertTrue(Abac::hasPermission($admin, 'posts:delete'));

        // Viewer has ONLY read
        $this->assertTrue(Abac::hasPermission($viewer, 'posts:read'));

        $this->assertFalse(Abac::hasPermission($viewer, 'posts:create'));
        $this->assertFalse(Abac::hasPermission($viewer, 'posts:delete'));
    }
}
