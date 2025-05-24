<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use DB;
use Auth;

class AdminTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if the Authorization header exists and is not empty
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }
    
        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');
    
        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }
    
        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);
    
        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }
    
        // Verify the token dynamically (check in the database)
        $user = DB::table('users')->where('api_token', $requestToken)->first();
    // dd($user);
        // dd($user); // This will output the user information
    
        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
    
        // Check if the user's role is Admin by looking up the roles table
        $role = DB::table('roles')->where('id', $user->role_id)->first();
    
        if (!$role || $role->name !== 'admin') {
            return response()->json([
                'error' => 'Access denied: Only Admins are allowed.'
            ], 403);
        }
    
        // Attach the user object to the request and set it as authenticated
        Auth::loginUsingId($user->id); // Manually login the user
    
        // Proceed with the request if token is valid and role is Admin
        return $next($request);
    }
    

}
