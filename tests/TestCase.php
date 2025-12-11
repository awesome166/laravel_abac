<?php

namespace Awesome\Abac\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Awesome\Abac\AwesomeAbacServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Awesome\Abac\Tests\Integration\TestUser;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AwesomeAbacServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('awesome-abac.models.user', TestUser::class);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('password')->nullable(); // Add password for seeder
            $table->timestamps();
        });
    }
}
