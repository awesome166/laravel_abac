<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Role;
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Facades\AbacPermissions;

class PivotAccessTest extends TestCase
{
    /** @test */
    public function it_filters_crud_permissions_based_on_pivot_access()
    {
        // 1. Create CRUD Permission
        $perm = Permission::create(['name' => 'posts', 'type' => 'crud']);

        // 2. Create Roles
        $editor = Role::create(['name' => 'Editor']);
        $viewer = Role::create(['name' => 'Viewer']);

        // Assign with Access restrictions using AssignedPermission
        // Editor: Can create, update, read. No delete.
        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id' => $editor->id,
            'assignee_type' => 'role',
            'account_id' => null,
            'access' => ['create', 'read', 'update'],
        ]);

        // Viewer: Can only read
        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id' => $viewer->id,
            'assignee_type' => 'role',
            'account_id' => null,
            'access' => ['read'],
        ]);

        // 4. Assign Roles to Users
        $ed = TestUser::create(['email' => 'ed@test.com']);
        $ed->roles()->attach($editor);

        $vi = TestUser::create(['email' => 'vi@test.com']);
        $vi->roles()->attach($viewer);

        // 5. Assertions

        // Editor
        $this->assertTrue(AbacPermissions::hasPermission($ed, 'posts:create'));
        $this->assertTrue(AbacPermissions::hasPermission($ed, 'posts:update'));
        $this->assertFalse(AbacPermissions::hasPermission($ed, 'posts:delete'));

        // Viewer
        $this->assertTrue(AbacPermissions::hasPermission($vi, 'posts:read'));
        $this->assertFalse(AbacPermissions::hasPermission($vi, 'posts:create'));
        $this->assertFalse(AbacPermissions::hasPermission($vi, 'posts:update'));
    }
}
