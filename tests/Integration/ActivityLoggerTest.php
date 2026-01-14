<?php

namespace AbacPermissions\Tests\Integration;

use AbacPermissions\Tests\TestCase;
use AbacPermissions\Models\ActivityLog;
use AbacPermissions\Models\Account;
use AbacPermissions\Logging\ActivityLogger;
use AbacPermissions\Tenancy\TenantContext;

class ActivityLoggerTest extends TestCase
{
    /** @test */
    public function it_logs_events_with_context()
    {
        $account = Account::create(['name' => 'Log Co', 'slug' => 'logs']);
        app(TenantContext::class)->setAccount($account);

        $user = TestUser::create(['email' => 'logger@test.com']);
        $this->actingAs($user);

        $logger = app(ActivityLogger::class);
        $logger->log('role.created', $user, ['role_name' => 'Admin']);

        $log = ActivityLog::first();

        $this->assertNotNull($log);
        $this->assertEquals('role.created', $log->event);
        $this->assertEquals($account->id, $log->tenant_id);
        $this->assertEquals($user->id, $log->subject_id);
        $this->assertEquals('Admin', $log->properties['role_name']);
    }
}
