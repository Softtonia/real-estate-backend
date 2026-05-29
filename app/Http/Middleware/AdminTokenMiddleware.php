<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Check Authorization header
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        $authorizationHeader = $request->header('Authorization');

        // Check Bearer format
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract token
        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Fetch user and role in one query
        $user = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.api_token', $requestToken)
            ->select(
                'users.id',
                'users.role_id',
                'users.isapproved',
                'roles.name as role_name'
            )
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        if ($user->role_name !== 'admin') {
            return response()->json([
                'error' => 'Access denied: Only Admins are allowed.'
            ], 403);
        }

        // Set authenticated user
        Auth::loginUsingId($user->id);

        return $next($request);
    }
}