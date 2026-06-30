<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
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
                'message' => 'API client authentication context is missing.',
            ], 401);
        }

        foreach ($permissions as $permission) {
            $resolvedPermission = $this->resolveDynamicPermission($request, $permission);

            if (
                $client->hasPermission($resolvedPermission)
                && $applicationPassword->canAccess($resolvedPermission)
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

    private function resolveDynamicPermission(Request $request, string $permission): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)(?:\.([a-zA-Z0-9_]+))?\}/', function ($matches) use ($request) {
            $routeParameterName = $matches[1];
            $attribute = $matches[2] ?? null;

            $value = $request->route($routeParameterName);

            if ($value instanceof Model) {
                if ($attribute) {
                    return (string) data_get($value, $attribute);
                }

                return (string) ($value->slug ?? $value->getKey());
            }

            return (string) $value;
        }, $permission);
    }
}