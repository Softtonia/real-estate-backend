<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Authorization: Bearer TOKEN
         */
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'status' => false,
                'error' => 'Please provide an API token.',
            ], 401);
        }

        /*
         * Token ke saath user aur role fetch karein.
         */
        $user = User::query()
            ->with([
                'role:id,name,is_admin_login_permission',
            ])
            ->where('api_token', $token)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'error' => 'Unauthorized. Invalid or expired API token.',
            ], 401);
        }

        if (!$user->role) {
            return response()->json([
                'status' => false,
                'error' => 'User role is not configured.',
            ], 403);
        }

        $roleName = strtolower(trim((string) $user->role->name));

        /*
         * Main admin hamesha allowed.
         * Custom role tab allowed jab is_admin_login_permission = 1 ho.
         */
        $isMainAdmin = $roleName === 'admin';

        $hasAdminPanelPermission =
            (int) $user->role->is_admin_login_permission === 1;

        if (!$isMainAdmin && !$hasAdminPanelPermission) {
            return response()->json([
                'status' => false,
                'error' => 'Access denied: Admin panel permission is required.',
            ], 403);
        }

        /*
         * Main admin ke alawa custom admin user active hona chahiye.
         */
        if (!$isMainAdmin && (int) $user->isapproved !== 1) {
            return response()->json([
                'status' => false,
                'error' => 'Access denied: Your account is not approved.',
            ], 403);
        }

        /*
         * Current request ke liye authenticated user set karein.
         */
        Auth::setUser($user);

        $request->setUserResolver(
            static fn () => $user
        );

        return $next($request);
    }
}