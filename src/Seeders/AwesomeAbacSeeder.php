<?php

namespace Awesome\Abac\Seeders;

use Illuminate\Database\Seeder;
use Awesome\Abac\Models\Account;
use Awesome\Abac\Models\Role;
use Awesome\Abac\Models\Permission;
use Awesome\Abac\Models\AssignedPermission;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AwesomeAbacSeeder extends Seeder
{
    public function run()
    {
        // 1. Create Comprehensive CRUD Permissions for all package resources
        $resources = [
            'accounts',
            'roles',
            'permissions',
            'users',
            'activity_logs',
            'assigned_permissions',
        ];

        $permissions = [];
        foreach ($resources as $resource) {
            $permissions[$resource] = Permission::firstOrCreate([
                'name' => $resource,
                'type' => 'crud',
                'description' => "Manage {$resource}",
            ]);
        }

        // 2. Create specific action permissions for attach/detach operations
        $actionPermissions = [
            'permissions.attach' => 'Attach permissions to roles or users',
            'permissions.detach' => 'Detach permissions from roles or users',
            'roles.assign' => 'Assign roles to users',
            'roles.revoke' => 'Revoke roles from users',
            'cache.flush' => 'Flush permission caches',
            'accounts.switch' => 'Switch between accounts',
        ];

        foreach ($actionPermissions as $name => $description) {
            $permissions[$name] = Permission::firstOrCreate([
                'name' => $name,
                'type' => 'on-off',
                'description' => $description,
            ]);
        }

        // 3. Create System Zeus Role (Global)
        $systemZeus = Role::firstOrCreate([
            'name' => 'System Zeus',
            'account_id' => null,
        ], [
            'zeus_level' => 'system',
            'description' => 'System-wide administrator with all permissions',
        ]);

        // 4. Create a Demo Tenant Account
        $tenant = Account::firstOrCreate([
            'slug' => 'demo-corp'
        ], [
            'name' => 'Demo Corporation',
            'plan' => 'enterprise',
            'metadata' => [
                'industry' => 'Technology',
                'size' => 'Medium',
            ],
        ]);

        // 5. Create Tenant Zeus Role
        $tenantZeus = Role::firstOrCreate([
            'name' => 'Tenant Owner',
            'account_id' => $tenant->id,
        ], [
            'zeus_level' => 'tenant',
            'description' => 'Account owner with all permissions within the account',
        ]);

        // 6. Create Standard Tenant Roles with specific permissions

        // Tenant Manager - Full access to users, roles, and permissions
        $tenantManager = Role::firstOrCreate([
            'name' => 'Tenant Manager',
            'account_id' => $tenant->id,
            'zeus_level' => 'none',
            'description' => 'Can manage users, roles, and permissions',
        ]);

        // Assign full CRUD to users, roles, and attach/detach permissions
        $managerPermissions = [
            ['permission' => $permissions['users'], 'access' => null], // Full CRUD
            ['permission' => $permissions['roles'], 'access' => ['read', 'update']], // Read and update only
            ['permission' => $permissions['permissions'], 'access' => ['read']], // Read only
            ['permission' => $permissions['permissions.attach'], 'access' => null],
            ['permission' => $permissions['permissions.detach'], 'access' => null],
            ['permission' => $permissions['roles.assign'], 'access' => null],
            ['permission' => $permissions['roles.revoke'], 'access' => null],
        ];

        foreach ($managerPermissions as $permData) {
            AssignedPermission::updateOrCreate([
                'permission_id' => $permData['permission']->id,
                'assignee_id' => $tenantManager->id,
                'assignee_type' => 'role',
                'account_id' => null,
            ], [
                'access' => $permData['access'],
            ]);
        }

        // Tenant Editor - Can create and update users
        $tenantEditor = Role::firstOrCreate([
            'name' => 'Tenant Editor',
            'account_id' => $tenant->id,
            'zeus_level' => 'none',
            'description' => 'Can create and edit users',
        ]);

        AssignedPermission::updateOrCreate([
            'permission_id' => $permissions['users']->id,
            'assignee_id' => $tenantEditor->id,
            'assignee_type' => 'role',
            'account_id' => null,
        ], [
            'access' => ['create', 'read', 'update'],
        ]);

        // Tenant Viewer - Read-only access
        $tenantViewer = Role::firstOrCreate([
            'name' => 'Tenant Viewer',
            'account_id' => $tenant->id,
            'zeus_level' => 'none',
            'description' => 'Read-only access to users and roles',
        ]);

        $viewerPermissions = [
            ['permission' => $permissions['users'], 'access' => ['read']],
            ['permission' => $permissions['roles'], 'access' => ['read']],
            ['permission' => $permissions['permissions'], 'access' => ['read']],
        ];

        foreach ($viewerPermissions as $permData) {
            AssignedPermission::updateOrCreate([
                'permission_id' => $permData['permission']->id,
                'assignee_id' => $tenantViewer->id,
                'assignee_type' => 'role',
                'account_id' => null,
            ], [
                'access' => $permData['access'],
            ]);
        }

        // 7. Create Users (Using Configured User Model)
        $userClass = config('awesome-abac.models.user', 'App\\Models\\User');

        if (class_exists($userClass)) {
            // System Admin
            $sysAdmin = $userClass::firstOrCreate(
                ['email' => 'zeus@system.com'],
                ['name' => 'System Administrator', 'password' => Hash::make('password')]
            );
            $sysAdmin->roles()->syncWithoutDetaching([$systemZeus->id]);

            // Tenant Owner
            $owner = $userClass::firstOrCreate(
                ['email' => 'owner@demo.com'],
                ['name' => 'Demo Owner', 'password' => Hash::make('password')]
            );
            $owner->accounts()->syncWithoutDetaching([$tenant->id]);
            $owner->roles()->syncWithoutDetaching([$tenantZeus->id]);

            // Tenant Manager
            $manager = $userClass::firstOrCreate(
                ['email' => 'manager@demo.com'],
                ['name' => 'Demo Manager', 'password' => Hash::make('password')]
            );
            $manager->accounts()->syncWithoutDetaching([$tenant->id]);
            $manager->roles()->syncWithoutDetaching([$tenantManager->id]);

            // Tenant Editor
            $editor = $userClass::firstOrCreate(
                ['email' => 'editor@demo.com'],
                ['name' => 'Demo Editor', 'password' => Hash::make('password')]
            );
            $editor->accounts()->syncWithoutDetaching([$tenant->id]);
            $editor->roles()->syncWithoutDetaching([$tenantEditor->id]);

            // Tenant Viewer
            $viewer = $userClass::firstOrCreate(
                ['email' => 'viewer@demo.com'],
                ['name' => 'Demo Viewer', 'password' => Hash::make('password')]
            );
            $viewer->accounts()->syncWithoutDetaching([$tenant->id]);
            $viewer->roles()->syncWithoutDetaching([$tenantViewer->id]);

            // 8. Add direct permission assignment example
            // Give the editor direct permission to view activity logs
            AssignedPermission::updateOrCreate([
                'permission_id' => $permissions['activity_logs']->id,
                'assignee_id' => $editor->id,
                'assignee_type' => 'user',
                'account_id' => $tenant->id,
            ], [
                'access' => ['read'],
            ]);

            echo "✓ Seeded AwesomeAbac package with:\n";
            echo "  - " . count($resources) . " CRUD permissions\n";
            echo "  - " . count($actionPermissions) . " action permissions\n";
            echo "  - 4 roles (System Zeus, Tenant Owner, Manager, Editor, Viewer)\n";
            echo "  - 5 users (zeus@system.com, owner@demo.com, manager@demo.com, editor@demo.com, viewer@demo.com)\n";
            echo "  - 1 demo account (demo-corp)\n";
            echo "  - All passwords: 'password'\n";
        }
    }
}
