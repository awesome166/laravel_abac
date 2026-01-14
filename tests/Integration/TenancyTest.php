<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use AbacPermissions\Models\Account;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Tenancy\TenantContext;

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
        $roleA = \AbacPermissions\Models\Role::create(['name' => 'Role A', 'account_id' => $accountA->id]);

        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id' => $roleA->id,
            'assignee_type' => 'role',
            'account_id' => null,
        ]);

        $user->roles()->attach($roleA);

        $roleB = \AbacPermissions\Models\Role::create(['name' => 'Role B', 'account_id' => $accountB->id]);
        // Role B has no permissions
        $user->roles()->attach($roleB);

        // Check A
        app(TenantContext::class)->setAccount($accountA);
        $this->assertTrue(\AbacPermissions\Facades\AbacPermissions::hasPermission($user, 'view_dashboard'));

        // Check B
        app(TenantContext::class)->setAccount($accountB);
        $this->assertFalse(\AbacPermissions\Facades\AbacPermissions::hasPermission($user, 'view_dashboard'));

        // Check Global (No Tenant)
        app(TenantContext::class)->clear();
        $this->assertFalse(\AbacPermissions\Facades\AbacPermissions::hasPermission($user, 'view_dashboard'));
    }
}
