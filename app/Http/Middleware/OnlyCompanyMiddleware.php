<?php

namespace App\Http\Middleware;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OnlyCompanyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
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

        // Check role = company
        if ($user->role && strtolower($user->role->name) === 'company') {
            return $next($request);
        }

        return response()->json(['error' => 'Forbidden. Only Company role is allowed.'], 403);
    }
}
