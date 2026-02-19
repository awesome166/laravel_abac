<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use AbacPermissions\Models\Account;
use AbacPermissions\Models\Role;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\AssignedPermission;
use AbacPermissions\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * DelegationTest
 *
 * Verifies the user-holdings delegation model:
 *   - Regular user can only delegate permissions they personally hold
 *   - Access is capped to what they have on each permission
 *   - Tenant Zeus can delegate ANY permission (they are the tenant super-admin)
 *   - System Zeus can delegate ANY permission with full access (global)
 *
 * No account-level grantable cap or grantable flag on user/role rows required.
 */
class DelegationTest extends TestCase
{
    private Account    $account;
    private Permission $postsPerm;
    private Permission $reportsPerm;
    private Permission $settingsPerm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account      = Account::create(['name' => 'Acme Corp', 'slug' => 'acme']);
        $this->postsPerm    = Permission::create(['name' => 'posts',    'type' => 'crud']);
        $this->reportsPerm  = Permission::create(['name' => 'reports',  'type' => 'on-off']);
        $this->settingsPerm = Permission::create(['name' => 'settings', 'type' => 'crud']);

        app(TenantContext::class)->setAccount($this->account);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function grantToUser(TestUser $user, Permission $perm, ?array $access = null): void
    {
        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id'   => $user->id,
            'assignee_type' => 'user',
            'account_id'    => $this->account->id,
            'access'        => $access,
        ]);
    }

    private function makeTenantZeus(): TestUser
    {
        $user = TestUser::create(['email' => 'tzeus@acme.com']);
        $role = Role::create([
            'name'       => 'Tenant Admin',
            'zeus_level' => 'tenant',
            'account_id' => $this->account->id,
        ]);
        $user->roles()->attach($role);
        return $user;
    }

    private function makeSystemZeus(): TestUser
    {
        $user = TestUser::create(['email' => 'zeus@system.com']);
        $role = Role::create(['name' => 'System Admin', 'zeus_level' => 'system']);
        $user->roles()->attach($role);
        return $user;
    }

    // =========================================================================
    // SCENARIO 1: User can only delegate what they personally hold
    // =========================================================================

    /** @test */
    public function user_can_delegate_permissions_they_hold()
    {
        $user = TestUser::create(['email' => 'user@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read', 'update']);

        $cap = $user->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->has($this->postsPerm->id));
        $this->assertEquals(['read', 'update'], $cap[$this->postsPerm->id]);
    }

    /** @test */
    public function user_cannot_delegate_permissions_they_do_not_hold()
    {
        $user = TestUser::create(['email' => 'user2@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read']);

        // user does NOT have settings
        $cap = $user->getGrantablePermissions($this->account->id);

        $this->assertFalse($cap->has($this->settingsPerm->id));
    }

    /** @test */
    public function user_cannot_delegate_beyond_their_own_access_level()
    {
        $user = TestUser::create(['email' => 'user3@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read', 'update']);

        $cap = $user->getGrantablePermissions($this->account->id);

        // They hold read+update — cannot delegate create or delete
        $this->assertNotContains('create', $cap[$this->postsPerm->id] ?? []);
        $this->assertNotContains('delete', $cap[$this->postsPerm->id] ?? []);
    }

    // =========================================================================
    // SCENARIO 2: Role-based permissions feed the delegation cap
    // =========================================================================

    /** @test */
    public function user_can_delegate_permissions_granted_via_role()
    {
        $role = Role::create(['name' => 'Editor', 'account_id' => $this->account->id]);
        AssignedPermission::create([
            'permission_id' => $this->postsPerm->id,
            'assignee_id'   => $role->id,
            'assignee_type' => 'role',
            'access'        => ['read', 'create'],
        ]);

        $user = TestUser::create(['email' => 'editor@acme.com']);
        $user->roles()->attach($role);

        $cap = $user->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->has($this->postsPerm->id));
        $this->assertEquals(['read', 'create'], $cap[$this->postsPerm->id]);
    }

    // =========================================================================
    // SCENARIO 3: authorizePermissionDelegation guard
    // =========================================================================

    /** @test */
    public function authorizePermissionDelegation_passes_when_within_own_holdings()
    {
        $user = TestUser::create(['email' => 'mgr@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read', 'update']);

        $payload = [['id' => $this->postsPerm->id, 'access' => ['read']]];

        $user->authorizePermissionDelegation($payload, $this->account->id);
        $this->assertTrue(true); // no exception
    }

    /** @test */
    public function authorizePermissionDelegation_throws_for_unowned_permission()
    {
        $this->expectException(AuthorizationException::class);

        $user = TestUser::create(['email' => 'mgr2@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read']);

        // user does NOT hold settings
        $payload = [['id' => $this->settingsPerm->id, 'access' => ['read']]];

        $user->authorizePermissionDelegation($payload, $this->account->id);
    }

    /** @test */
    public function authorizePermissionDelegation_throws_when_access_exceeds_own_holdings()
    {
        $this->expectException(AuthorizationException::class);

        $user = TestUser::create(['email' => 'mgr3@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read', 'update']);

        // User only holds read+update but tries to delegate delete
        $payload = [['id' => $this->postsPerm->id, 'access' => ['read', 'delete']]];

        $user->authorizePermissionDelegation($payload, $this->account->id);
    }

    // =========================================================================
    // SCENARIO 4: Tenant Zeus gets all permissions — they are the top tenant admin
    // =========================================================================

    /** @test */
    public function tenant_zeus_can_delegate_any_permission()
    {
        $tenantZeus = $this->makeTenantZeus();

        $cap = $tenantZeus->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->has($this->postsPerm->id),    'Tenant Zeus: posts');
        $this->assertTrue($cap->has($this->reportsPerm->id),  'Tenant Zeus: reports');
        $this->assertTrue($cap->has($this->settingsPerm->id), 'Tenant Zeus: settings');
        $this->assertNull($cap[$this->postsPerm->id], 'Tenant Zeus gets full (null) access');
    }

    /** @test */
    public function tenant_zeus_delegation_does_not_throw_for_any_permission()
    {
        $tenantZeus = $this->makeTenantZeus();

        $payload = [
            ['id' => $this->postsPerm->id,    'access' => ['create', 'delete']],
            ['id' => $this->settingsPerm->id,  'access' => null],
            ['id' => $this->reportsPerm->id,   'access' => ['on']],
        ];

        $tenantZeus->authorizePermissionDelegation($payload, $this->account->id);
        $this->assertTrue(true);
    }

    // =========================================================================
    // SCENARIO 5: System Zeus bypasses everything
    // =========================================================================

    /** @test */
    public function system_zeus_gets_all_permissions_with_full_access()
    {
        $sysZeus = $this->makeSystemZeus();

        $cap = $sysZeus->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->has($this->postsPerm->id));
        $this->assertTrue($cap->has($this->reportsPerm->id));
        $this->assertTrue($cap->has($this->settingsPerm->id));
        $this->assertNull($cap[$this->postsPerm->id]);
    }

    /** @test */
    public function system_zeus_delegation_does_not_throw_for_any_permission()
    {
        $sysZeus = $this->makeSystemZeus();

        $payload = [
            ['id' => $this->postsPerm->id,    'access' => ['create', 'delete']],
            ['id' => $this->settingsPerm->id,  'access' => null],
        ];

        $sysZeus->authorizePermissionDelegation($payload, $this->account->id);
        $this->assertTrue(true);
    }

    // =========================================================================
    // SCENARIO 6: No account context yields empty cap for regular users
    // =========================================================================

    /** @test */
    public function user_with_no_assignments_gets_empty_delegation_cap()
    {
        $user = TestUser::create(['email' => 'empty@acme.com']);
        // No permissions assigned at all

        $cap = $user->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->isEmpty());
    }
}
