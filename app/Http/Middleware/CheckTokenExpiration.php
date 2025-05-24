<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;

class CheckTokenExpiration
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
{
    $token = $request->bearerToken();

    if (!$token) {
        return response()->json(['message' => 'Unauthorized. No token provided.'], 401);
    }

    $user = User::where('api_token', $token)->first();

    if (!$user) {
        return response()->json(['message' => 'Unauthorized. Invalid token.'], 401);
    }

    // Check if the token has expired (24 hours)
    if ($user->token_created_at && $user->token_created_at < now()->subHours(24)) {
        // Remove the expired token
        $user->update(['api_token' => null]);

        return response()->json(['message' => 'Unauthorized. Token expired. Please log in again.'], 401);
    }

    // Do NOT update token_created_at on every request
    return $next($request);
}

}
