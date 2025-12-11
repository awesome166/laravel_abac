<?php

namespace Awesome\Abac\Tests\Integration;

use Awesome\Abac\Tests\TestCase;
use Awesome\Abac\Models\ActivityLog;
use Awesome\Abac\Models\Account;
use Awesome\Abac\Logging\ActivityLogger;
use Awesome\Abac\Tenancy\TenantContext;

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
