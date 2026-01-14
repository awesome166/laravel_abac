<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use AbacPermissions\Models\Permission;
use AbacPermissions\Models\Role;
use AbacPermissions\Models\Account;
use AbacPermissions\Seeders\AbacPermissionsSeeder;
use Illuminate\Support\Facades\DB;

class SeederTest extends TestCase
{
    /** @test */
    public function it_seeds_default_data()
    {
        $this->seed(AbacPermissionsSeeder::class);

        // Check Permissions
        $this->assertTrue(Permission::where('name', 'users')->exists());
        $this->assertEquals('crud', Permission::where('name', 'users')->first()->type);

        // Check Roles
        $this->assertTrue(Role::where('name', 'System Zeus')->exists());
        $this->assertTrue(Role::where('name', 'Tenant Owner')->exists());

        // Check Account
        $this->assertTrue(Account::where('slug', 'demo-corp')->exists());

        // Check Users
        $this->assertDatabaseHas('users', ['email' => 'zeus@system.com']);
        $this->assertDatabaseHas('users', ['email' => 'owner@demo.com']);
    }
}
