<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AdminDeveloperMiddleware
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

        // Fetch the user by token and check if isapproved == 1
        $user = User::where('api_token', $requestToken)->where('isapproved', 1)->with('role')->first();

        // If user not found or not approved, return unauthorized response
        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token or user not approved.'], 401);
        }

        // Check if user has a valid role
        if (!$user->role || !isset($user->role->name)) {
            return response()->json(['error' => 'User role is missing or invalid.'], 403);
        }

        // Allow only users with roles "admin" or "developer"
        if (!in_array($user->role->name, ['admin', 'developer'])) {
            return response()->json(['error' => 'Access denied. You do not have the required role.'], 403);
        }

        // Authenticate the user
        Auth::setUser($user);

        return $next($request);
    }
}
