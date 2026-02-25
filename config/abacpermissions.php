<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenancy Feature Toggle
    |--------------------------------------------------------------------------
    |
    | Enable or disable multi-tenancy. If disabled, the package acts as a
    | comprehensive ABAC/RBAC system only.
    |
    */
    'tenancy_enabled' => env('ABACPERMISSIONS_TENANCY_ENABLED', env('ABACPERMISSIONS_SAAS_MODE', true)),

    /*
    |--------------------------------------------------------------------------
    | SaaS Mode (Alias for Tenancy Toggle)
    |--------------------------------------------------------------------------
    |
    | When disabled, tenant isolation features are turned off and the package
    | can be used as a single-application ABAC/RBAC system.
    |
    */
    'saas_mode' => env('ABACPERMISSIONS_SAAS_MODE', env('ABACPERMISSIONS_TENANCY_ENABLED', true)),

    /*
    |--------------------------------------------------------------------------
    | Database Tables Configuration
    |--------------------------------------------------------------------------
    |
    | Custom names for tables used by the package.
    |
    */
    'tables' => [
        'accounts' => 'accounts',
        'roles' => 'roles',
        'permissions' => 'permissions',
        'permission_role' => 'permission_role',
        'account_user' => 'account_user', // Pivot table
        'assigned_permissions' => 'assigned_permissions',
        'activity_logs' => 'activity_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for permission caching.
    |
    */
    'cache' => [
        'key_prefix' => 'abacpermissions_',
        'ttl' => 60 * 60, // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Classes used for internal logic. You can extend and replace these.
    |
    */
    'models' => [
        'user' => \App\Models\User::class, // Defaults to App\Models\User
        'account' => \AbacPermissions\Models\Account::class,
        'role' => \AbacPermissions\Models\Role::class,
        'permission' => \AbacPermissions\Models\Permission::class,
        'assigned_permission' => \AbacPermissions\Models\AssignedPermission::class,
        'activity_log' => \AbacPermissions\Models\ActivityLog::class,
    ],
];
