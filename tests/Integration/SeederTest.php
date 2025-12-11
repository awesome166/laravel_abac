<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Models\Account;
use Awesome\Abac\Seeders\AwesomeAbacSeeder;
use Illuminate\Support\Facades\DB;

class SeederTest extends TestCase
{
    /** @test */
    public function it_seeds_default_data()
    {
        $this->seed(AwesomeAbacSeeder::class);

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
