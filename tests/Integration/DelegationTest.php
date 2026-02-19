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
 * Verifies the two-tier permission delegation system:
 *   Level 1 → Account capability cap (what the tenant is allowed to distribute)
 *   Level 2 → User delegation cap (intersection of personal holdings ∩ account cap)
 *
 * Zeus levels:
 *   System Zeus → bypasses all caps
 *   Tenant Zeus → has full account cap, no personal-holding check
 *   Regular user → intersection only
 */
class DelegationTest extends TestCase
{
    private Account    $account;
    private Permission $postsPerm;   // crud
    private Permission $reportsPerm; // on-off
    private Permission $settingsPerm; // crud

    protected function setUp(): void
    {
        parent::setUp();

        $this->account      = Account::create(['name' => 'Acme Corp', 'slug' => 'acme']);
        $this->postsPerm    = Permission::create(['name' => 'posts',    'type' => 'crud']);
        $this->reportsPerm  = Permission::create(['name' => 'reports',  'type' => 'on-off']);
        $this->settingsPerm = Permission::create(['name' => 'settings', 'type' => 'crud']);

        // Set tenant context
        app(TenantContext::class)->setAccount($this->account);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Assign a permission to an account as grantable cap */
    private function grantToAccount(Permission $perm, ?array $access = null): void
    {
        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id'   => $this->account->id,
            'assignee_type' => 'account',
            'account_id'    => null,
            'access'        => $access,
            'grantable'     => true,
        ]);
    }

    /** Directly assign a permission to a user */
    private function grantToUser(TestUser $user, Permission $perm, ?array $access = null, bool $grantable = false): void
    {
        AssignedPermission::create([
            'permission_id' => $perm->id,
            'assignee_id'   => $user->id,
            'assignee_type' => 'user',
            'account_id'    => $this->account->id,
            'access'        => $access,
            'grantable'     => $grantable,
        ]);
    }

    /** Create a Tenant Zeus user */
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

    /** Create a System Zeus user */
    private function makeSystemZeus(): TestUser
    {
        $user = TestUser::create(['email' => 'zeus@system.com']);
        $role = Role::create(['name' => 'System Admin', 'zeus_level' => 'system']);
        $user->roles()->attach($role);
        return $user;
    }

    // =========================================================================
    // SCENARIO 1: Account cap limits what admin assigns
    // =========================================================================

    /** @test */
    public function account_cap_blocks_permission_not_in_cap()
    {
        // Account cap ONLY has posts (not settings)
        $this->grantToAccount($this->postsPerm, ['read', 'update']);

        $user = TestUser::create(['email' => 'user@acme.com']);
        // User personally holds settings — but account cap doesn't include it
        $this->grantToUser($user, $this->settingsPerm, ['read', 'create']);

        $cap = $user->getGrantablePermissions($this->account->id);

        $this->assertFalse($cap->has($this->settingsPerm->id),
            'settings should not be grantable — not in account cap');
    }

    // =========================================================================
    // SCENARIO 2: User can grant what they hold AND is in account cap
    // =========================================================================

    /** @test */
    public function user_can_grant_intersection_of_own_permissions_and_account_cap()
    {
        // Account cap: posts with ['read', 'update', 'create', 'delete']
        $this->grantToAccount($this->postsPerm);

        $user = TestUser::create(['email' => 'mgr@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read', 'update']);

        $cap = $user->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->has($this->postsPerm->id));
        $this->assertEquals(['read', 'update'], $cap[$this->postsPerm->id]);
    }

    // =========================================================================
    // SCENARIO 3: User cannot grant beyond own access even if cap allows it
    // =========================================================================

    /** @test */
    public function user_cannot_grant_beyond_own_access()
    {
        // Account cap: posts full access
        $this->grantToAccount($this->postsPerm);

        $user = TestUser::create(['email' => 'mgr2@acme.com']);
        // User only holds read+update personally
        $this->grantToUser($user, $this->postsPerm, ['read', 'update']);

        $cap = $user->getGrantablePermissions($this->account->id);

        // Should NOT include 'create' or 'delete' even though account cap has them
        $this->assertNotContains('create', $cap[$this->postsPerm->id] ?? []);
        $this->assertNotContains('delete', $cap[$this->postsPerm->id] ?? []);
    }

    // =========================================================================
    // SCENARIO 4: Delegation authorization throws on cap violation
    // =========================================================================

    /** @test */
    public function authorizePermissionDelegation_throws_when_permission_outside_cap()
    {
        $this->expectException(AuthorizationException::class);

        // Account cap: only posts
        $this->grantToAccount($this->postsPerm);

        $user = TestUser::create(['email' => 'mgr3@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read']);

        // Try to delegate settings — completely outside account cap
        $payload = [
            ['id' => $this->settingsPerm->id, 'access' => ['read']],
        ];

        $user->authorizePermissionDelegation($payload, $this->account->id);
    }

    /** @test */
    public function authorizePermissionDelegation_throws_when_access_exceeds_cap()
    {
        $this->expectException(AuthorizationException::class);

        // Account cap: posts read+update only
        $this->grantToAccount($this->postsPerm, ['read', 'update']);

        $user = TestUser::create(['email' => 'mgr4@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read', 'update', 'delete']);

        // User tries to delegate 'delete' — not in account cap
        $payload = [
            ['id' => $this->postsPerm->id, 'access' => ['read', 'delete']],
        ];

        $user->authorizePermissionDelegation($payload, $this->account->id);
    }

    /** @test */
    public function authorizePermissionDelegation_passes_for_valid_delegation()
    {
        // Account cap: posts full access
        $this->grantToAccount($this->postsPerm);

        $user = TestUser::create(['email' => 'mgr5@acme.com']);
        $this->grantToUser($user, $this->postsPerm, ['read', 'update']);

        // Delegate only what user personally holds — should pass without exception
        $payload = [
            ['id' => $this->postsPerm->id, 'access' => ['read']],
        ];

        $user->authorizePermissionDelegation($payload, $this->account->id);
        $this->assertTrue(true); // reached here without exception
    }

    // =========================================================================
    // SCENARIO 5: Tenant Zeus gets full account cap, not just own permissions
    // =========================================================================

    /** @test */
    public function tenant_zeus_gets_full_account_cap_regardless_of_personal_assignments()
    {
        // Account cap: posts full access + reports on-off
        $this->grantToAccount($this->postsPerm);
        $this->grantToAccount($this->reportsPerm);

        $tenantZeus = $this->makeTenantZeus();
        // Note: tenantZeus has NO direct permission assignments to posts or reports

        $cap = $tenantZeus->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->has($this->postsPerm->id),
            'Tenant Zeus should be able to grant posts (in account cap)');
        $this->assertTrue($cap->has($this->reportsPerm->id),
            'Tenant Zeus should be able to grant reports (in account cap)');
    }

    /** @test */
    public function tenant_zeus_is_capped_by_account_cap_and_cannot_grant_outside_it()
    {
        // Account cap: ONLY posts
        $this->grantToAccount($this->postsPerm);

        $tenantZeus = $this->makeTenantZeus();

        $cap = $tenantZeus->getGrantablePermissions($this->account->id);

        // settings is not in the account cap — tenant zeus cannot grant it
        $this->assertFalse($cap->has($this->settingsPerm->id),
            'Tenant Zeus should NOT grant settings — not in account cap');
    }

    /** @test */
    public function tenant_zeus_delegation_throws_for_permission_outside_account_cap()
    {
        $this->expectException(AuthorizationException::class);

        $this->grantToAccount($this->postsPerm); // only posts in cap

        $tenantZeus = $this->makeTenantZeus();

        $payload = [
            ['id' => $this->settingsPerm->id, 'access' => ['read']],
        ];

        $tenantZeus->authorizePermissionDelegation($payload, $this->account->id);
    }

    // =========================================================================
    // SCENARIO 6: System Zeus bypasses all caps
    // =========================================================================

    /** @test */
    public function system_zeus_gets_all_permissions_uncapped()
    {
        // No account cap assigned at all — System Zeus still gets everything
        $sysZeus = $this->makeSystemZeus();

        $cap = $sysZeus->getGrantablePermissions($this->account->id);

        $this->assertTrue($cap->has($this->postsPerm->id));
        $this->assertTrue($cap->has($this->reportsPerm->id));
        $this->assertTrue($cap->has($this->settingsPerm->id));

        // Access should be null (full) for all
        $this->assertNull($cap[$this->postsPerm->id]);
    }

    /** @test */
    public function system_zeus_delegation_does_not_throw_for_any_permission()
    {
        $sysZeus = $this->makeSystemZeus();

        // No account cap at all — doesn't matter for system zeus
        $payload = [
            ['id' => $this->postsPerm->id,    'access' => ['create', 'delete']],
            ['id' => $this->settingsPerm->id,  'access' => null],
            ['id' => $this->reportsPerm->id,   'access' => ['on']],
        ];

        $sysZeus->authorizePermissionDelegation($payload, $this->account->id);
        $this->assertTrue(true); // no exception
    }
}
