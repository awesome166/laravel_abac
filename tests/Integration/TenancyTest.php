<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\Account;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Models\AssignedPermission;
use Awesome\Abac\Tenancy\TenantContext;

class TenancyTest extends TestCase
{
    /** @test */
    public function it_scopes_permissions_to_account_context()
    {
        // 1. Setup Accounts
        $accountA = Account::create(['name' => 'A', 'slug' => 'a']);
        $accountB = Account::create(['name' => 'B', 'slug' => 'b']);

        // 2. Setup Permissions & Users
        $user = TestUser::create(['email' => 'user@test.com']);
        $perm = Permission::create(['name' => 'view_dashboard']);

        // Grant Permission in A (Directly) using AssignedPermission
        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id' => $user->id,
            'assignee_type' => 'user',
            'account_id' => $accountA->id,
        ]);

        // 3. Test with Role-based tenancy
        $roleA = \Awesome\Abac\Models\Role::create(['name' => 'Role A', 'account_id' => $accountA->id]);

        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id' => $roleA->id,
            'assignee_type' => 'role',
            'account_id' => null,
        ]);

        $user->roles()->attach($roleA);

        $roleB = \Awesome\Abac\Models\Role::create(['name' => 'Role B', 'account_id' => $accountB->id]);
        // Role B has no permissions
        $user->roles()->attach($roleB);

        // Check A
        app(TenantContext::class)->setAccount($accountA);
        $this->assertTrue(\Awesome\Abac\Facades\Abac::hasPermission($user, 'view_dashboard'));

        // Check B
        app(TenantContext::class)->setAccount($accountB);
        $this->assertFalse(\Awesome\Abac\Facades\Abac::hasPermission($user, 'view_dashboard'));

        // Check Global (No Tenant)
        app(TenantContext::class)->clear();
        $this->assertFalse(\Awesome\Abac\Facades\Abac::hasPermission($user, 'view_dashboard'));
    }
}
