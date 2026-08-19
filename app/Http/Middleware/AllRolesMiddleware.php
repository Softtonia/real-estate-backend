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
            return response()->json(['status' => false, 'error' => 'Please provide an API token.'], 401);
        }

        // Retrieve and validate token format
        $authorizationHeader = $request->header('Authorization');
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['status' => false, 'error' => 'Invalid token format. Token must start with "Bearer ".'], 401);
        }

        // Extract the token
        $requestToken = substr($authorizationHeader, 7);
        if (empty($requestToken)) {
            return response()->json(['status' => false, 'error' => 'Token is missing.'], 401);
        }

        // Manually authenticate the user
        try {
            $user = \App\Models\User::where('api_token', $requestToken)->firstOrFail();
            Auth::setUser($user);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'error' => 'Unauthorized. Invalid or expired token.'], 401);
        }

        return $next($request);
    }


}
