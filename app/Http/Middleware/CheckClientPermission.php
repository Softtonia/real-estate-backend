<?php

namespace App\Http\Middleware;

use App\Support\ApiPermission;
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

        if (! $client || ! $applicationPassword) {
            return response()->json([
                'success' => false,
                'message' => 'API client authentication context is missing.',
            ], 401);
        }

        $resolvedPermissions = [];

        foreach ($permissions as $permission) {
            $resolvedPermission = $this->resolveDynamicPermission($request, $permission);

            if ($resolvedPermission === '') {
                continue;
            }

            $resolvedPermissions[] = $resolvedPermission;

            if (
                $client->hasPermission($resolvedPermission)
                && $this->applicationPasswordCanAccess($applicationPassword, $resolvedPermission)
            ) {
                return $next($request);
            }
        }

        $payload = [
            'success' => false,
            'message' => 'This API client does not have permission to access this resource.',
        ];

        if (app()->environment('local') || $request->header('X-Debug-API-Client') === '1') {
            $payload['required_permissions'] = $permissions;
            $payload['resolved_permissions'] = $resolvedPermissions;
            $payload['client_permissions'] = $client->permissions ?? [];
            $payload['application_password_abilities'] = $applicationPassword->abilities ?? [];
        }

        return response()->json($payload, 403);
    }

    private function applicationPasswordCanAccess($applicationPassword, string $permission): bool
    {
        if (method_exists($applicationPassword, 'canAccess')) {
            return $applicationPassword->canAccess($permission);
        }

        return ApiPermission::matches(
            $applicationPassword->abilities ?? [],
            $permission
        );
    }

    private function resolveDynamicPermission(Request $request, string $permission): string
    {
        $permission = trim($permission);

        if ($permission === '') {
            return '';
        }

        $resolvedPermission = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)(?:\.([a-zA-Z0-9_]+))?\}/',
            function ($matches) use ($request) {
                $routeParameterName = $matches[1];
                $attribute = $matches[2] ?? null;

                $value = $request->route($routeParameterName);

                if ($value instanceof Model) {
                    if ($attribute) {
                        return (string) data_get($value, $attribute, '');
                    }

                    return (string) ($value->slug ?? $value->getKey());
                }

                return (string) $value;
            },
            $permission
        );

        return ApiPermission::normalize((string) $resolvedPermission);
    }
}