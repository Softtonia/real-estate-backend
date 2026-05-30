<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ApiTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Check if Authorization header exists
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve and validate token format
        $authorizationHeader = $request->header('Authorization');

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract token
        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Cache key based on token
        $cacheKey = 'api_token_user:' . $requestToken;

        // Try to get from cache
        $user = Cache::remember($cacheKey, 60, function () use ($requestToken) {
            return User::select([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'role_id',
                'isapproved',
                'kyc',
                'api_token',
            ])
                ->where('api_token', $requestToken)
                ->first();
        });

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Set authenticated user for controller usage
        Auth::setUser($user);

        return $next($request);
    }
}
