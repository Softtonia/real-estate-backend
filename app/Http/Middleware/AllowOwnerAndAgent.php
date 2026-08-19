<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AllowOwnerAndAgent
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorizationHeader = $request->header('Authorization');

        if (!$authorizationHeader || !str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['status' => false, 'error' => 'Invalid or missing Authorization header.'], 401);
        }

        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['status' => false, 'error' => 'Token is missing.'], 401);
        }

        $user = DB::table('users')
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->where('users.api_token', $requestToken)
            ->select('users.id', 'roles.name as role')
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        if (!in_array(strtolower($user->role), ['agent', 'owner'])) {
            return response()->json(['error' => 'Unauthorized. Only agent or owner can access this.'], 403);
        }

        // Set the user as authenticated for the current request
        Auth::loginUsingId($user->id);

        return $next($request);
    }
}
