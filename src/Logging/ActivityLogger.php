<?php

namespace Awesome\Abac\Logging;

use Awesome\Abac\Models\ActivityLog;
use Awesome\Abac\Tenancy\TenantContext;

class ActivityLogger
{
    public function log(string $event, $subject = null, array $properties = [])
    {
        $context = app(TenantContext::class);
        $user = auth()->user();

        ActivityLog::create([
            'tenant_id' => $context->getAccountId(),
            'event' => $event,
            'causer_type' => $user ? get_class($user) : null,
            'causer_id' => $user->id ?? null,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject->id ?? null,
            'properties' => $properties,
        ]);
    }
}
