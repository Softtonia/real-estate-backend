<?php

namespace App\Http\Middleware;

use Closure;
use DB;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

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

        // Extract the token
        $requestToken = substr($authorizationHeader, 7);
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Fetch the user by token
        $user = User::where('api_token', $requestToken)->first();

        // If user not found, return unauthorized response
        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Manually authenticate the user
        Auth::setUser($user);

        return $next($request);
    }}
