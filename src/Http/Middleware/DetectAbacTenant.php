<?php

namespace AbacPermissions\Http\Middleware;

use Closure;
use AbacPermissions\Models\Account;
use AbacPermissions\Tenancy\TenantContext;

class DetectAbacTenant
{
    public function handle($request, Closure $next)
    {
        if (!config('abacpermissions.tenancy_enabled')) {
            return $next($request);
        }

        // Simplistic detection: header or subdomain.
        // For AbacPermissions, let's look for 'X-Tenant-ID' or 'X-Account-Slug'.

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
