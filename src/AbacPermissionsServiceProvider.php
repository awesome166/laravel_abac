<?php

namespace AbacPermissions;

use Illuminate\Support\ServiceProvider;
use AbacPermissions\AccessControl\AbacEngine;
use AbacPermissions\Cache\PermissionCacheInvalidator;
use AbacPermissions\Logging\ActivityLogger;
use AbacPermissions\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class AbacPermissionsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/abacpermissions.php' => config_path('abacpermissions.php'),
        ], 'abacpermissions-config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        if (config('abacpermissions.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/abacpermissions.php');
        }

        // Observers
        $this->registerConfiguredObservers();

        // Register Middleware alias
        $router = $this->app['router'];
        $router->aliasMiddleware('abac.tenant', \AbacPermissions\Http\Middleware\DetectAbacTenant::class);
        $router->aliasMiddleware('abac.append', \AbacPermissions\Http\Middleware\AppendPermissions::class);
        $router->aliasMiddleware('abac.auth', \AbacPermissions\Http\Middleware\ShareAbacAuthPayload::class);

        if (config('abacpermissions.middleware.auto_apply_tenant', false)) {
            foreach (config('abacpermissions.middleware.auto_apply_groups', ['api']) as $group) {
                $router->pushMiddlewareToGroup($group, \AbacPermissions\Http\Middleware\DetectAbacTenant::class);
            }
        }

        if (config('abacpermissions.middleware.auto_apply_auth_payload', false)) {
            foreach (config('abacpermissions.middleware.auth_payload_groups', ['api']) as $group) {
                $router->pushMiddlewareToGroup($group, \AbacPermissions\Http\Middleware\ShareAbacAuthPayload::class);
            }
        }

        if (config('abacpermissions.cache.observe_role_user_queries', true)) {
            DB::listen(function (QueryExecuted $query) {
                $sql = strtolower(ltrim($query->sql));
                if (!str_contains($sql, 'role_user')) {
                    return;
                }

                if (!str_starts_with($sql, 'insert') && !str_starts_with($sql, 'update') && !str_starts_with($sql, 'delete')) {
                    return;
                }

                $this->app->make(PermissionCacheInvalidator::class)->invalidateGlobal();
            });
        }
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

        $this->app->singleton(PermissionCacheInvalidator::class, function () {
            return new PermissionCacheInvalidator();
        });

        // ABAC Engine (Service)
        $this->app->singleton('abacpermissions', function ($app) {
            return new AbacEngine(
                $app->make(TenantContext::class),
                $app->make(PermissionCacheInvalidator::class)
            );
        });

        // Activity Logger
        $this->app->singleton(ActivityLogger::class, function ($app) {
            return new ActivityLogger();
        });
    }

    protected function registerConfiguredObservers(): void
    {
        $map = [
            config('abacpermissions.models.permission') => \AbacPermissions\Observers\PermissionObserver::class,
            config('abacpermissions.models.role') => \AbacPermissions\Observers\RoleObserver::class,
            config('abacpermissions.models.assigned_permission') => \AbacPermissions\Observers\AssignedPermissionObserver::class,
            config('abacpermissions.models.account') => \AbacPermissions\Observers\AccountObserver::class,
        ];

        foreach ($map as $modelClass => $observerClass) {
            if (!is_string($modelClass) || !class_exists($modelClass)) {
                continue;
            }

            if (!is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            $modelClass::observe($observerClass);
        }
    }
}
