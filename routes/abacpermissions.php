<?php

use Illuminate\Support\Facades\Route;
use AbacPermissions\Http\Controllers\PermissionManagementController;

Route::prefix(config('abacpermissions.routes.prefix', 'abac'))
    ->middleware(config('abacpermissions.routes.middleware', ['api', 'auth', 'abac.tenant']))
    ->group(function () {
        Route::get('/permissions', [PermissionManagementController::class, 'index']);
        Route::get('/assigned/{type}/{id}', [PermissionManagementController::class, 'getAssigned']);
        Route::post('/sync/{type}/{id}', [PermissionManagementController::class, 'sync']);
        Route::get('/grantable', [PermissionManagementController::class, 'grantable']);
        Route::get('/user-accounts', [PermissionManagementController::class, 'userAccounts']);
    });

