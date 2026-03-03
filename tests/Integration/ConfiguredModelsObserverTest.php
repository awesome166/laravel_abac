<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class ConfiguredModelsObserverTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('abacpermissions.models.permission', \AbacPermissions\Tests\Fixtures\CustomPermission::class);
        $app['config']->set('abacpermissions.models.role', \AbacPermissions\Tests\Fixtures\CustomRole::class);
        $app['config']->set('abacpermissions.models.assigned_permission', \AbacPermissions\Tests\Fixtures\CustomAssignedPermission::class);
    }

    /** @test */
    public function custom_model_classes_from_config_still_trigger_observers()
    {
        Cache::forever('abacpermissions_version', 1);

        \AbacPermissions\Tests\Fixtures\CustomPermission::create([
            'name' => 'custom.model.permission',
            'type' => 'on-off',
        ]);

        $this->assertGreaterThan(1, Cache::get('abacpermissions_version'));
    }
}

