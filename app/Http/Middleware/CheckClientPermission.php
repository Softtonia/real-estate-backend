<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckClientPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $client = $request->attributes->get('api_client');
        $applicationPassword = $request->attributes->get('application_password');

        if (!$client || !$applicationPassword) {
            return response()->json([
                'success' => false,
                'message' => 'API client context missing.',
            ], 401);
        }

        if (empty($permissions)) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if (
                $client->hasPermission($permission)
                && $applicationPassword->canAccess($permission)
            ) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'This API client does not have permission to access this resource.',
            'required_permissions' => $permissions,
        ], 403);
    }
}