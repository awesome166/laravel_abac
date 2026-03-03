<?php

namespace AbacPermissions\Http\Middleware;

use AbacPermissions\Facades\AbacPermissions;
use Closure;
use Illuminate\Http\JsonResponse;

class ShareAbacAuthPayload
{
    public function handle($request, Closure $next)
    {
        if (auth()->check() && class_exists(\Inertia\Inertia::class)) {
            \Inertia\Inertia::share('abac', AbacPermissions::getFrontendAuthPayload(auth()->user()));
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && auth()->check()) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $data['_abac_auth'] = AbacPermissions::getFrontendAuthPayload(auth()->user());
                $response->setData($data);
            }
        }

        return $response;
    }
}

