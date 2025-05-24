<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
class CheckApiTokenExpiry
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    // public function handle(Request $request, Closure $next)
    // {
    //     // Check if the user is authenticated and has an API token
    //     if (Auth::check() && Auth::user()->api_token) {
    //         $user = Auth::user();
    //         $currentTime = Carbon::now();

    //         // Ensure token_created_at is a Carbon instance
    //         $tokenCreatedAt = Carbon::parse($user->token_created_at);

    //        // If the token is older than 1 hour, invalidate it
    //         if ($tokenCreatedAt->diffInMinutes($currentTime) >= 60) {
    //             $user->api_token = null;  // Remove the API token
    //             $user->token_created_at = null;  // Optionally remove the token creation timestamp as well
    //             $user->save();  // Save the changes to the database
    //         }

    //     }

    //     return $next($request);
    // }

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

    // Token expiration check (2 minutes)
    if ($user->last_active_at && \Carbon\Carbon::parse($user->last_active_at)->lt(now()->subMinutes(1))) {
        // Revoke expired token
        $user->update(['api_token' => null]);

        return response()->json(['message' => 'Unauthorized. Token expired.'], 401);
    }

    // Update last active time only if necessary
    $user->update(['last_active_at' => now()]);

    return $next($request);
}

    
}
