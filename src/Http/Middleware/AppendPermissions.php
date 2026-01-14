<?php

namespace AbacPermissions\Http\Middleware;

use Closure;
use AbacPermissions\Facades\AbacPermissions;
use Illuminate\Http\JsonResponse;

class AppendPermissions
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof JsonResponse && auth()->check()) {
            $data = $response->getData(true);
            $permissions = AbacPermissions::getPermissions(auth()->user());

            // Append to meta or root?
            // "Appended to every response automatically"
            // Usually in a `meta` field or `_permissions`.
            if (is_array($data)) {
                $data['_permissions'] = $permissions;
                $response->setData($data);
            }
        }

        return $response;
    }
}
