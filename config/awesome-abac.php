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
    'tenancy_enabled' => true,

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
        'key_prefix' => 'awesome_abac_',
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
        'account' => \Awesome\Abac\Models\Account::class,
        'role' => \Awesome\Abac\Models\Role::class,
        'permission' => \Awesome\Abac\Models\Permission::class,
        'assigned_permission' => \Awesome\Abac\Models\AssignedPermission::class,
        'activity_log' => \Awesome\Abac\Models\ActivityLog::class,
    ],
];
