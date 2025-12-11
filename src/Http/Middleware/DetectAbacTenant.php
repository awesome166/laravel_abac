<?php

namespace Awesome\Abac\Http\Middleware;

use Closure;
use Awesome\Abac\Models\Account;
use Awesome\Abac\Tenancy\TenantContext;

class DetectAbacTenant
{
    public function handle($request, Closure $next)
    {
        if (!config('awesome-abac.tenancy_enabled')) {
            return $next($request);
        }

        // Simplistic detection: header or subdomain.
        // For AwesomeAbac, let's look for 'X-Tenant-ID' or 'X-Account-Slug'.

        $slug = $request->header('X-Account-Slug');

        if ($slug) {
            $account = Account::where('slug', $slug)->first();
            if ($account) {
                app(TenantContext::class)->setAccount($account);
            }
        }

        return $next($request);
    }
}
