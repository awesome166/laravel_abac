<?php

namespace Awesome\Abac;

use Illuminate\Support\ServiceProvider;
use Awesome\Abac\AccessControl\AbacEngine;
use Awesome\Abac\Logging\ActivityLogger;
use Awesome\Abac\Tenancy\TenantContext;

class AwesomeAbacServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/awesome-abac.php' => config_path('awesome-abac.php'),
        ], 'awesome-abac-config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Observers
        \Awesome\Abac\Models\Permission::observe(\Awesome\Abac\Observers\PermissionObserver::class);
        \Awesome\Abac\Models\Role::observe(\Awesome\Abac\Observers\RoleObserver::class);

        // Register Middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('abac.tenant', \Awesome\Abac\Http\Middleware\DetectAbacTenant::class);
        $router->aliasMiddleware('abac.append', \Awesome\Abac\Http\Middleware\AppendPermissions::class);
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/awesome-abac.php', 'awesome-abac'
        );

        // Core Tenancy Context
        $this->app->singleton(TenantContext::class, function ($app) {
            return new TenantContext();
        });

        // ABAC Engine (Service)
        $this->app->singleton('awesome.abac', function ($app) {
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
