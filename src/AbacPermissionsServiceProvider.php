<?php

namespace AbacPermissions;

use Illuminate\Support\ServiceProvider;
use AbacPermissions\AccessControl\AbacEngine;
use AbacPermissions\Logging\ActivityLogger;
use AbacPermissions\Tenancy\TenantContext;

class AbacPermissionsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/abacpermissions.php' => config_path('abacpermissions.php'),
        ], 'abacpermissions-config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Observers
        \AbacPermissions\Models\Permission::observe(\AbacPermissions\Observers\PermissionObserver::class);
        \AbacPermissions\Models\Role::observe(\AbacPermissions\Observers\RoleObserver::class);

        // Register Middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('abac.tenant', \AbacPermissions\Http\Middleware\DetectAbacTenant::class);
        $router->aliasMiddleware('abac.append', \AbacPermissions\Http\Middleware\AppendPermissions::class);
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/abacpermissions.php', 'abacpermissions'
        );

        // Core Tenancy Context
        $this->app->singleton(TenantContext::class, function ($app) {
            return new TenantContext();
        });

        // ABAC Engine (Service)
        $this->app->singleton('abacpermissions', function ($app) {
            return new AbacEngine(
                $app->make(TenantContext::class),
                $app['cache.store']
            );
        });

        // Activity Logger
        $this->app->singleton(ActivityLogger::class, function ($app) {
            return new ActivityLogger();
        });
    }
}
