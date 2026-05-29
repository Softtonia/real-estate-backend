<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class AdminOrConsultancyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        $authorizationHeader = $request->header('Authorization');

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        $user = User::with('role:id,name')
            ->select([
                'id',
                'role_id',
                'isapproved',
                'kyc',
                'api_token',
            ])
            ->where('api_token', $requestToken)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $roleName = optional($user->role)->name;

        if (!in_array($roleName, ['admin', 'consultancy'])) {
            return response()->json([
                'error' => 'Access denied: Only Admin or Consultancy users are allowed.'
            ], 403);
        }

        Auth::setUser($user);

        return $next($request);
    }
}