<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Models\Account;
use AbacPermissions\Models\Role;
use AbacPermissions\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use AbacPermissions\Traits\HasAbac;
use AbacPermissions\Tenancy\UsesTenant;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [
            \AbacPermissions\AbacPermissionsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('abacpermissions.tenancy_enabled', true);
        $app['config']->set('abacpermissions.tables', [
            'accounts' => 'accounts',
            'roles' => 'roles',
            'permissions' => 'permissions',
            'permission_role' => 'permission_role',
            'account_user' => 'account_user',
            'assigned_permissions' => 'assigned_permissions',
            'activity_logs' => 'activity_logs',
        ]);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Create test tables for specific models
        Schema::create('test_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('abacpermissions.models.user', TestUser::class);
    }

    /** @test */
    public function regular_user_sees_only_own_tenant_within_context()
    {
        $account1 = Account::create(['name' => 'Acct 1', 'slug' => 'acct-1']);
        $account2 = Account::create(['name' => 'Acct 2', 'slug' => 'acct-2']);

        TestPost::create(['title' => 'Post 1', 'account_id' => $account1->id]);
        TestPost::create(['title' => 'Post 2', 'account_id' => $account2->id]);
        TestPost::create(['title' => 'Global Post', 'account_id' => null]);

        $user = TestUser::create(['name' => 'Regular', 'email' => 'reg@test.com']);
        $this->actingAs($user);

        // Set Context to Account 1
        app(TenantContext::class)->setAccount($account1);

        $posts = TestPost::all();
        $this->assertCount(1, $posts);
        $this->assertEquals('Post 1', $posts->first()->title);
    }

    /** @test */
    public function regular_user_sees_global_or_nothing_without_context()
    {
        $account1 = Account::create(['name' => 'Acct 1', 'slug' => 'acct-1']);
        TestPost::create(['title' => 'Post 1', 'account_id' => $account1->id]);
        TestPost::create(['title' => 'Global Post', 'account_id' => null]);

        $user = TestUser::create(['name' => 'Regular', 'email' => 'reg@test.com']);
        $this->actingAs($user);

        // No Context set

        $posts = TestPost::all();
        // Regular user should see only Global Post (where account_id is null)
        $this->assertCount(1, $posts);
        $this->assertEquals('Global Post', $posts->first()->title);
    }

    /** @test */
    public function zeus_user_sees_all_without_context()
    {
        $account1 = Account::create(['name' => 'Acct 1', 'slug' => 'acct-1']);
        $account2 = Account::create(['name' => 'Acct 2', 'slug' => 'acct-2']);
        TestPost::create(['title' => 'Post 1', 'account_id' => $account1->id]);
        TestPost::create(['title' => 'Post 2', 'account_id' => $account2->id]);
        TestPost::create(['title' => 'Global Post', 'account_id' => null]);

        $user = TestUser::create(['name' => 'Zeus', 'email' => 'zeus@test.com']);

        $zeusRole = Role::create(['name' => 'System Zeus', 'zeus_level' => 'system']);
        $user->roles()->attach($zeusRole);

        $this->actingAs($user);

        // No Context set
        // Zeus override should kick in and show everything
        $posts = TestPost::all();
        $this->assertCount(3, $posts);
    }

    /** @test */
    public function zeus_user_sees_specific_tenant_with_context()
    {
        $account1 = Account::create(['name' => 'Acct 1', 'slug' => 'acct-1']);
        $account2 = Account::create(['name' => 'Acct 2', 'slug' => 'acct-2']);
        TestPost::create(['title' => 'Post 1', 'account_id' => $account1->id]);
        TestPost::create(['title' => 'Post 2', 'account_id' => $account2->id]);

        $user = TestUser::create(['name' => 'Zeus', 'email' => 'zeus@test.com']);
        $zeusRole = Role::create(['name' => 'System Zeus', 'zeus_level' => 'system']);
        $user->roles()->attach($zeusRole);

        $this->actingAs($user);

        // Set Context to Account 1
        // Even Zeus should be scoped if they explicitly select a tenant context?
        // Logic says: if ($accountId) { where... } else { check zeus }
        // So YES, if context is set, strict scoping applies. This is desirable (Zeus impersonating/working within a tenant).

        app(TenantContext::class)->setAccount($account1);

        $posts = TestPost::all();
        $this->assertCount(1, $posts);
        $this->assertEquals('Post 1', $posts->first()->title);
    }
}

class TestPost extends Model
{
    use UsesTenant;
    protected $guarded = [];
    public $timestamps = false;
}

class TestUser extends \Illuminate\Foundation\Auth\User
{
    use HasAbac;
    protected $table = 'users';
    protected $guarded = [];
}
