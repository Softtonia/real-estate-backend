<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class AllRolesMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
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

        // Extract the token
        $requestToken = substr($authorizationHeader, 7);
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Manually authenticate the user
        try {
            // Assuming you're using Laravel Passport or a similar package for token validation
            $user = \App\Models\User::where('api_token', $requestToken)->firstOrFail();
            Auth::setUser($user);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid or expired token.'], 422);
        }

        return $next($request);
    }


}
