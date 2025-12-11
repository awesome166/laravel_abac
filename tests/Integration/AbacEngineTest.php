<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\Account;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Facades\Abac;
use Awesome\Abac\Tenancy\TenantContext;

class AbacEngineTest extends TestCase
{
    /** @test */
    public function it_expands_crud_permissions()
    {
        $perm = Permission::create(['name' => 'posts', 'type' => 'crud']);
        $expanded = $perm->expand();

        $this->assertContains('posts:create', $expanded);
        $this->assertContains('posts:delete', $expanded);
    }

    /** @test */
    public function it_checks_zeus_bypass()
    {
        $user = TestUser::create(['email' => 'admin@system.com']);
        $role = Role::create(['name' => 'System Admin', 'zeus_level' => 'system']);

        $user->roles()->attach($role); // Global assignment

        // Should have everything
        $this->assertTrue(Abac::hasPermission($user, 'nuclear:launch'));
    }

    /** @test */
    public function it_checks_tenant_zeus_bypass()
    {
        $account = Account::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = TestUser::create(['email' => 'admin@acme.com']);

        $role = Role::create([
            'account_id' => $account->id,
            'name' => 'Tenant Admin',
            'zeus_level' => 'tenant'
        ]);

        $user->roles()->attach($role);

        // Context: Acme
        app(TenantContext::class)->setAccount($account);
        $this->assertTrue(Abac::hasPermission($user, 'posts:delete'));

        // Context: Other
        app(TenantContext::class)->clear();
        $this->assertFalse(Abac::hasPermission($user, 'posts:delete'));
    }
}
