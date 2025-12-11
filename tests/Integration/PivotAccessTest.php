<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Models\AssignedPermission;
use Awesome\Abac\Facades\Abac;

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
        $this->assertTrue(Abac::hasPermission($ed, 'posts:create'));
        $this->assertTrue(Abac::hasPermission($ed, 'posts:update'));
        $this->assertFalse(Abac::hasPermission($ed, 'posts:delete'));

        // Viewer
        $this->assertTrue(Abac::hasPermission($vi, 'posts:read'));
        $this->assertFalse(Abac::hasPermission($vi, 'posts:create'));
        $this->assertFalse(Abac::hasPermission($vi, 'posts:update'));
    }
}
