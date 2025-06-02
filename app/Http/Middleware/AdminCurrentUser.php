<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminCurrentUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    // public function handle(Request $request, Closure $next): Response
    // {
    //      // Check if Authorization header exists
    //      if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //         return response()->json(['error' => 'Please provide an API token.'], 422);
    //     }

    //     // Validate token format
    //     $authorizationHeader = $request->header('Authorization');
    //     if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //         return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
    //     }

    //     // Extract token
    //     $token = substr($authorizationHeader, 7);
    //     if (empty($token)) {
    //         return response()->json(['error' => 'Token is missing.'], 422);
    //     }

    //     // Find user by api_token and load role
    //     $user = User::with('role')->where('api_token', $token)->first();
    //     if (!$user) {
    //         return response()->json(['error' => 'Invalid or expired token.'], 401);
    //     }

    //     // Set user for request
    //     $request->setUserResolver(fn () => $user);

    //     $requestedUserId = $request->route('id') ?? $request->query('id');

    //     // Admin can access any user
    //     if ($user->role && $user->role->name === 'admin') {
    //         return $next($request);
    //     }

    //     // Others can only access their own record
    //     if ($user->id == $requestedUserId) {
    //         return $next($request);
    //     }

    //     return response()->json(['error' => 'Unauthorized access.'], 403);
    // }


    public function handle(Request $request, Closure $next): Response
    {
        // Check for Authorization header
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Validate token format
        $authorizationHeader = $request->header('Authorization');
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract token
        $token = substr($authorizationHeader, 7);
        if (empty($token)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Get user by token
        $user = User::with('role')->where('api_token', $token)->first();
        if (!$user) {
            return response()->json(['error' => 'Invalid or expired token.'], 401);
        }

        // Set user to request
        $request->setUserResolver(fn () => $user);

        // Get user ID from route or query
        $requestedUserId = $request->route('id') ?? $request->query('id');

        // If no user ID is provided, treat it as self-access
        if (!$requestedUserId || $user->id == $requestedUserId) {
            return $next($request);
        }

        // If user is admin, allow access to any user's data
        if ($user->role && strtolower($user->role->name) === 'admin') {
            return $next($request);
        }

        // Otherwise, block access
        return response()->json(['error' => 'Unauthorized access.'], 403);
    }
}
