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
        'observe_role_user_queries' => env('ABACPERMISSIONS_OBSERVE_ROLE_USER_QUERIES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Routes
    |--------------------------------------------------------------------------
    |
    | Built-in management routes for permissions, assignment sync, grantable
    | lookups, and user account discovery.
    |
    */
    'routes' => [
        'enabled' => env('ABACPERMISSIONS_ROUTES_ENABLED', true),
        'prefix' => env('ABACPERMISSIONS_ROUTE_PREFIX', 'abac'),
        'middleware' => ['api', 'auth', 'abac.tenant'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Auto-Apply
    |--------------------------------------------------------------------------
    |
    | Optionally push tenant detection middleware into route groups
    | automatically so no Kernel changes are needed.
    |
    */
    'middleware' => [
        'auto_apply_tenant' => env('ABACPERMISSIONS_AUTO_TENANT_MIDDLEWARE', false),
        'auto_apply_groups' => ['api'],
        'auto_apply_auth_payload' => env('ABACPERMISSIONS_AUTO_AUTH_PAYLOAD_MIDDLEWARE', false),
        'auth_payload_groups' => ['api'],
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
