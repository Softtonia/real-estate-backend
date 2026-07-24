<?php
// PermissionController.php

namespace App\Http\Controllers;

use App\Models\UserPermission;
// use App\Models\Permission;
// use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{


    public function assignPermission(Request $request)
    {
        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|exists:roles,id', // Ensure the role ID exists

            'permission_id' => 'required|string', // Ensure the permission name is provided
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find or create the permission
        $permission = Permission::firstOrCreate(
            ['id' => $request->permission_id, 'guard_name' => 'web']
        );

        // Find the role by ID
        $role = Role::findById($request->role_id);

        // Check if role exists
        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        // Assign the permission to the role if it's not already assigned
        if (!$role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        // Return success response
        return response()->json(['message' => 'Permission assigned to role successfully']);
    }



    public function removePermission(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permission' => ['nullable', 'string', 'max:255'],
            'permission_id' => ['nullable', 'integer', 'exists:permissions,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$request->filled('permission') && !$request->filled('permission_id')) {
            return response()->json([
                'status' => false,
                'message' => 'Permission name or permission id is required.',
            ], 422);
        }

        $guardName = config('permission_modules.guard', 'sanctum');

        $role = SpatieRole::query()
            ->where('id', (int) $request->role_id)
            ->where('guard_name', $guardName)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Role not found for guard: ' . $guardName,
            ], 404);
        }

        $permissionQuery = Permission::query()
            ->where('guard_name', $guardName);

        if ($request->filled('permission_id')) {
            $permissionQuery->where('id', (int) $request->permission_id);
        } else {
            $permissionQuery->where('name', strtolower(trim((string) $request->permission)));
        }

        $permission = $permissionQuery->first();

        if (!$permission) {
            return response()->json([
                'status' => false,
                'message' => 'Permission not found for guard: ' . $guardName,
            ], 404);
        }

        if (!$role->hasPermissionTo($permission->name, $guardName)) {
            return response()->json([
                'status' => false,
                'message' => 'This permission is not assigned to this role.',
            ], 422);
        }

        DB::transaction(function () use ($role, $permission) {
            $role->revokePermissionTo($permission);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'status' => true,
            'message' => 'Permission removed successfully.',
            'data' => [
                'role_id' => (int) $role->id,
                'role_name' => $role->name,
                'permission' => $permission->name,
                'guard_name' => $guardName,
            ],
        ]);
    }
    public function deletePermission(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'permission' => ['nullable', 'string', 'max:255'],
            'permission_id' => ['nullable', 'integer', 'exists:permissions,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$request->filled('permission') && !$request->filled('permission_id')) {
            return response()->json([
                'status' => false,
                'message' => 'Permission name or permission id is required.',
            ], 422);
        }

        $guardName = config('permission_modules.guard', 'sanctum');

        $permissionQuery = Permission::query()
            ->where('guard_name', $guardName);

        if ($request->filled('permission_id')) {
            $permissionQuery->where('id', (int) $request->permission_id);
        } else {
            $permissionQuery->where('name', strtolower(trim((string) $request->permission)));
        }

        $permission = $permissionQuery->first();

        if (!$permission) {
            return response()->json([
                'status' => false,
                'message' => 'Permission not found for guard: ' . $guardName,
            ], 404);
        }

        $assignedRolesCount = $permission->roles()->count();
        $isForceDelete = (bool) $request->boolean('force');

        if ($assignedRolesCount > 0 && !$isForceDelete) {
            return response()->json([
                'status' => false,
                'message' => 'Permission is assigned to roles. Remove it from roles first or send force=true.',
                'data' => [
                    'permission_id' => (int) $permission->id,
                    'permission' => $permission->name,
                    'assigned_roles_count' => $assignedRolesCount,
                ],
            ], 422);
        }

        DB::transaction(function () use ($permission) {
            $permission->roles()->detach();

            DB::table('model_has_permissions')
                ->where('permission_id', $permission->id)
                ->delete();

            $permission->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'status' => true,
            'message' => 'Permission deleted successfully.',
            'data' => [
                'permission_id' => (int) $permission->id,
                'permission' => $permission->name,
                'guard_name' => $guardName,
            ],
        ]);
    }
    public function index(): JsonResponse
    {
        $guardName = config('permission_modules.guard', 'sanctum');
        $modules = config('permission_modules.modules', []);
        $defaultActions = config('permission_modules.actions', []);

        $permissions = Permission::query()
            ->where('guard_name', $guardName)
            ->select(['id', 'name', 'guard_name'])
            ->orderBy('name')
            ->get()
            ->keyBy('name');

        $matrix = collect($modules)
            ->map(function (array $moduleConfig, string $moduleKey) use (
                $defaultActions,
                $permissions
            ) {
                $actions = $moduleConfig['actions'] ?? $defaultActions;

                return [
                    'module' => $moduleKey,
                    'label' => $moduleConfig['label'] ?? ucwords(str_replace('_', ' ', $moduleKey)),
                    'actions' => collect($actions)
                        ->mapWithKeys(function (string $action) use ($moduleKey, $permissions) {
                            $permissionName = $moduleKey . '.' . $action;
                            $permission = $permissions->get($permissionName);

                            return [
                                $action => [
                                    'id' => $permission ? (int) $permission->id : null,
                                    'action' => $action,
                                    'permission' => $permissionName,
                                    'label' => ucfirst($action),
                                    'exists' => (bool) $permission,
                                ],
                            ];
                        })
                        ->toArray(),
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'status' => true,
            'message' => 'Permissions fetched successfully.',
            'data' => [
                'guard_name' => $guardName,
                'modules_count' => count($matrix),
                'permissions_count' => $permissions->count(),
                'modules' => $matrix,
            ],
        ]);
    }



    public function assignDynamicPermissions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $guardName = config('permission_modules.guard', 'sanctum');

        $role = SpatieRole::query()
            ->where('id', (int) $request->role_id)
            ->where('guard_name', $guardName)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Role not found for guard: ' . $guardName,
            ], 404);
        }

        $allowedPermissionNames = $this->configuredPermissionNames();

        $permissionNames = collect($request->input('permissions', []))
            ->flatMap(function ($permission) {
                if (is_string($permission)) {
                    return [$permission];
                }

                if (is_array($permission)) {
                    if (!empty($permission['permission'])) {
                        return [$permission['permission']];
                    }

                    if (!empty($permission['module'])) {
                        return collect(['read', 'create', 'edit', 'delete'])
                            ->filter(fn($action) => !empty($permission[$action]))
                            ->map(fn($action) => $permission['module'] . '.' . $action)
                            ->values()
                            ->toArray();
                    }
                }

                return [];
            })
            ->map(fn($permission) => strtolower(trim((string) $permission)))
            ->filter()
            ->unique()
            ->values();

        $invalidPermissions = $permissionNames
            ->reject(fn($permission) => in_array($permission, $allowedPermissionNames, true))
            ->values()
            ->toArray();

        if (!empty($invalidPermissions)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid permissions found.',
                'errors' => [
                    'permissions' => $invalidPermissions,
                ],
            ], 422);
        }

        DB::transaction(function () use ($permissionNames, $guardName, $role) {
            foreach ($permissionNames as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => $guardName,
                ]);
            }

            $permissions = Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $permissionNames)
                ->pluck('name')
                ->toArray();

            $role->syncPermissions($permissions);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'status' => true,
            'message' => 'Permissions assigned successfully.',
            'data' => [
                'role_id' => (int) $role->id,
                'role_name' => $role->name,
                'guard_name' => $guardName,
                'permissions' => $permissionNames,
                'permissions_count' => $permissionNames->count(),
            ],
        ]);
    }

    public function getPermissionsByRole($role_id): JsonResponse
    {
        $guardName = config('permission_modules.guard', 'sanctum');
        $modules = config('permission_modules.modules', []);
        $defaultActions = config('permission_modules.actions', []);

        $role = \Spatie\Permission\Models\Role::query()
            ->where('id', (int) $role_id)
            ->where('guard_name', $guardName)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Role not found for guard: ' . $guardName,
            ], 404);
        }

        $selectedPermissions = $role->permissions()
            ->where('guard_name', $guardName)
            ->pluck('name')
            ->map(fn($name) => strtolower((string) $name))
            ->values()
            ->toArray();

        $matrix = collect($modules)
            ->map(function (array $moduleConfig, string $moduleKey) use (
                $defaultActions,
                $selectedPermissions
            ) {
                $actions = $moduleConfig['actions'] ?? $defaultActions;

                return [
                    'module' => $moduleKey,
                    'label' => $moduleConfig['label'] ?? ucwords(str_replace('_', ' ', $moduleKey)),
                    'actions' => collect($actions)
                        ->mapWithKeys(function (string $action) use ($moduleKey, $selectedPermissions) {
                            $permissionName = strtolower($moduleKey . '.' . $action);

                            return [
                                $action => [
                                    'action' => $action,
                                    'permission' => $permissionName,
                                    'label' => ucfirst($action),
                                    'selected' => in_array($permissionName, $selectedPermissions, true),
                                ],
                            ];
                        })
                        ->toArray(),
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'status' => true,
            'message' => 'Role permissions fetched successfully.',
            'data' => [
                'role' => [
                    'id' => (int) $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                ],
                'selected_permissions' => $selectedPermissions,
                'selected_permissions_count' => count($selectedPermissions),
                'modules' => $matrix,
            ],
        ]);
    }

    public function getModelNames()
    {
        $modules = config('permission_modules.modules', []);
        $defaultActions = config('permission_modules.actions', []);

        $data = collect($modules)
            ->map(function (array $module, string $key) use ($defaultActions) {
                $actions = $module['actions'] ?? $defaultActions;

                return [
                    'module' => $key,
                    'label' => $module['label'] ?? ucwords(str_replace('_', ' ', $key)),
                    'actions' => collect($actions)
                        ->map(function (string $action) use ($key) {
                            return [
                                'action' => $action,
                                'permission' => $key . '.' . $action,
                                'label' => ucfirst($action),
                            ];
                        })
                        ->values()
                        ->toArray(),
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'status' => true,
            'message' => 'Permission modules fetched successfully.',
            'data' => $data,
        ]);
    }

    public function syncConfiguredPermissions(Request $request): JsonResponse
    {
        $guardName = config('permission_modules.guard', 'sanctum');
        $modules = config('permission_modules.modules', []);
        $defaultActions = config('permission_modules.actions', []);

        if (empty($modules)) {
            return response()->json([
                'status' => false,
                'message' => 'No permission modules configured.',
            ], 422);
        }

        $created = 0;
        $existing = 0;
        $permissions = [];

        DB::transaction(function () use (
            $modules,
            $defaultActions,
            $guardName,
            &$created,
            &$existing,
            &$permissions
        ) {
            foreach ($modules as $moduleKey => $moduleConfig) {
                $actions = $moduleConfig['actions'] ?? $defaultActions;

                foreach ($actions as $action) {
                    $permissionName = $moduleKey . '.' . $action;

                    $permission = Permission::where('name', $permissionName)
                        ->where('guard_name', $guardName)
                        ->first();

                    if ($permission) {
                        $existing++;
                    } else {
                        $permission = Permission::create([
                            'name' => $permissionName,
                            'guard_name' => $guardName,
                        ]);

                        $created++;
                    }

                    $permissions[] = [
                        'id' => (int) $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'module' => $moduleKey,
                        'action' => $action,
                    ];
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'status' => true,
            'message' => 'Permissions synced successfully.',
            'data' => [
                'guard_name' => $guardName,
                'created_count' => $created,
                'existing_count' => $existing,
                'total_count' => count($permissions),
                'permissions' => $permissions,
            ],
        ]);
    }
    private function configuredPermissionNames(): array
    {
        $modules = config('permission_modules.modules', []);
        $defaultActions = config('permission_modules.actions', []);

        $permissions = [];

        foreach ($modules as $moduleKey => $moduleConfig) {
            $actions = $moduleConfig['actions'] ?? $defaultActions;

            foreach ($actions as $action) {
                $permissions[] = strtolower($moduleKey . '.' . $action);
            }
        }

        return array_values(array_unique($permissions));
    }
    public function currentUserPermissions(Request $request): JsonResponse
    {
        $user = $this->resolvePermissionUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated user.',
            ], 401);
        }

        if (!$user->role_id) {
            return response()->json([
                'status' => false,
                'message' => 'User role is not assigned.',
            ], 403);
        }

        $guardName = config('permission_modules.guard', 'sanctum');
        $modules = config('permission_modules.modules', []);
        $defaultActions = config('permission_modules.actions', []);

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

        $permissionNames = $role->permissions()
            ->where('guard_name', $guardName)
            ->pluck('name')
            ->map(fn($name) => strtolower((string) $name))
            ->unique()
            ->values()
            ->toArray();

        $permissionMap = collect($permissionNames)
            ->mapWithKeys(fn($permission) => [$permission => true])
            ->toArray();

        $matrix = collect($modules)
            ->map(function (array $moduleConfig, string $moduleKey) use (
                $defaultActions,
                $permissionMap
            ) {
                $actions = $moduleConfig['actions'] ?? $defaultActions;

                return [
                    'module' => $moduleKey,
                    'label' => $moduleConfig['label'] ?? ucwords(str_replace('_', ' ', $moduleKey)),
                    'actions' => collect($actions)
                        ->mapWithKeys(function (string $action) use ($moduleKey, $permissionMap) {
                            $permissionName = strtolower($moduleKey . '.' . $action);

                            return [
                                $action => [
                                    'permission' => $permissionName,
                                    'allowed' => isset($permissionMap[$permissionName]),
                                ],
                            ];
                        })
                        ->toArray(),
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'status' => true,
            'message' => 'Current user permissions fetched successfully.',
            'data' => [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->first_name ?? $user->name ?? null,
                    'email' => $user->email ?? null,
                    'role_id' => (int) $user->role_id,
                    'role_name' => $role->name,
                ],
                'guard_name' => $guardName,
                'permissions' => $permissionNames,
                'permission_map' => $permissionMap,
                'modules' => $matrix,
            ],
        ]);
    }
    private function resolvePermissionUser(Request $request): ?User
    {
        $authUser = Auth::user();

        if ($authUser instanceof User) {
            return $authUser;
        }

        $token = $request->bearerToken();

        if (!$token && $request->filled('api_token')) {
            $token = $request->input('api_token');
        }

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }
}
