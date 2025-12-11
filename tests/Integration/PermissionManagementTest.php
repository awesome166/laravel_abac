<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Http\Controllers\PermissionManagementController;
use Illuminate\Http\Request;

class PermissionManagementTest extends TestCase
{
    /** @test */
    public function it_lists_permissions()
    {
        Permission::create(['name' => 'posts', 'type' => 'crud']);

        $controller = new PermissionManagementController();
        $response = $controller->index();

        $this->assertCount(1, $response);
        $this->assertEquals(['create', 'read', 'update', 'delete'], $response[0]['available_actions']);
    }

    /** @test */
    public function it_syncs_role_permissions()
    {
        $perm = Permission::create(['name' => 'products', 'type' => 'crud']);
        $role = Role::create(['name' => 'Manager']);

        $controller = new PermissionManagementController();

        // Mock Request
        $request = Request::create('/sync', 'POST');
        $request->replace([
            'permissions' => [
                ['id' => $perm->id, 'access' => ['read', 'update']]
            ]
        ]);

        $controller->sync($request, 'role', $role->id);

        // Verify assignment was created
        $assignment = \Awesome\Abac\Models\AssignedPermission::where('assignee_id', $role->id)
            ->where('assignee_type', 'role')
            ->where('permission_id', $perm->id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertEquals(['read', 'update'], $assignment->access);
    }
}
