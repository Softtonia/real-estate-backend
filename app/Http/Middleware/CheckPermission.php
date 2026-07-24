<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        if ($this->isSuperAdmin($user)) {
            return $next($request);
        }

        if (!$user->role_id) {
            return response()->json([
                'status' => false,
                'message' => 'User role is not assigned.',
            ], 403);
        }

        $guardName = config('permission_modules.guard', 'sanctum');
        $permissionName = strtolower(trim($module)) . '.' . strtolower(trim($action));

        $role = SpatieRole::query()
            ->where('id', (int) $user->role_id)
            ->where('guard_name', $guardName)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Role not found for permission guard.',
            ], 403);
        }

        $hasPermission = $role->permissions()
            ->where('permissions.guard_name', $guardName)
            ->where('permissions.name', $permissionName)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have permission to perform this action.',
                'error' => 'Missing permission: ' . $permissionName,
            ], 403);
        }

        return $next($request);
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $authUser = Auth::user();

        if ($authUser instanceof User) {
            return $authUser;
        }

        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if (!$token) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function isSuperAdmin(User $user): bool
    {
        if ((int) $user->id === 1) {
            return true;
        }

        if ((int) $user->role_id === 1) {
            return true;
        }

        if (!$user->role_id) {
            return false;
        }

        $guardName = config('permission_modules.guard', 'sanctum');

        $role = SpatieRole::query()
            ->where('id', (int) $user->role_id)
            ->where('guard_name', $guardName)
            ->first();

        if (!$role) {
            return false;
        }

        $roleName = strtolower(trim((string) $role->name));
        $roleName = str_replace([' ', '_'], '-', $roleName);

        return in_array($roleName, [
            'admin',
            'super-admin',
            'administrator',
        ], true);
    }
}