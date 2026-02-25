<?php

namespace AbacPermissions\Http\Middleware;

use Closure;
use AbacPermissions\Models\Account;
use AbacPermissions\Tenancy\TenantContext;
use AbacPermissions\Facades\AbacPermissions;

class DetectAbacTenant
{
    public function handle($request, Closure $next)
    {
        $tenancyEnabled = config('abacpermissions.tenancy_enabled', config('abacpermissions.saas_mode', true));

        if (!$tenancyEnabled) {
            return $next($request);
        }

        $user = auth()->user();

        // Check for explicit account context via headers
        $accountId = $request->header('X-Account-ID');
        $slug = $request->header('X-Account-Slug');

        $account = null;

        if ($accountId) {
            $account = Account::find($accountId);
        } elseif ($slug) {
            $account = Account::where('slug', $slug)->first();
        }

        // Set account context if found
        if ($account) {
            app(TenantContext::class)->setAccount($account);
        } else {
            // No explicit account header provided
            // Check if user is System Zeus - they can proceed without account context
            // System Zeus has access to all data across all tenants
            if ($user && AbacPermissions::isSystemZeus($user)) {
                // Allow System Zeus to proceed without account context
                // They will see all data via TenantScope override
                return $next($request);
            }

            // Regular users without an account context will be restricted
            // to global resources only (account_id IS NULL)
        }

        return $next($request);
    }
}
