<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
// use App\Models\Role;
use App\Models\User;
use Log;
use Spatie\Permission\Models\Role;
// use DB;
use Illuminate\Validation\Rule;
use App\Models\RolePrefixReapeater;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class Rolecontroller extends Controller
{
    public function createRole(Request $request)
    {
        try {
            // Check if the Authorization header exists
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
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            // Validate the request data
            $validatedData = $request->validate([
                'name' => 'required|unique:roles|max:255',
                'role_prefixes' => ['required', 'string', Rule::unique('roles', 'prefix')],  // Validate unique role_prefixe
            ]);

            // Check if the role name already exists in the database
            $checkRole = Role::where('name', $request->name)->first();

            if ($checkRole) {
                return response()->json(['error' => 'Role name is already taken'], 400);
            }

            // Create a new role with the guard_name set to 'sanctum'
            $role = Role::create([
                'name' => $request->name,
                'prefix' => Str::upper($request->role_prefixes),
                'is_admin_login_permission' => $request->is_admin_login_permission ?? 0,
                'created_by' => $user->id,  // The authenticated user's ID is used here
                'guard_name' => 'sanctum'   // Set the guard_name to 'sanctum'
            ]);

            // Return a success response with the role data
            return response()->json([
                'status' => true,
                'message' => 'Role Added Successfully',
                'data' => $role
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return validation error response
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            // Handle unexpected errors
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    public function assignRole(Request $request)
    {
        try {
            // Validate the incoming request data
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'role_id' => 'required', // Assuming you pass the role_id from Postman
            ]);

            // Find the user to whom the role will be assigned
            $userToAssign = User::findOrFail($request->user_id);

            // Find the role by its ID
            $role = Role::findOrFail($request->role_id);

            // Check if the user already has the role
            if (!$userToAssign->hasRole($role)) {
                // Assign the role to the user
                $userToAssign->assignRole($role);
                return response()->json(['message' => 'Role assigned successfully'], 200);
            } else {
                // Return message if the role is already assigned to the user
                return response()->json(['message' => 'Role already assigned to the user'], 200);
            }
        } catch (ValidationException $e) {
            // Handle validation errors
            return response()->json(['error' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            // Handle the case where user or role is not found
            return response()->json(['error' => 'User or role not found'], 404);
        } catch (\Exception $e) {
            // Handle other exceptions
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function editrole(Request $request)
    {
        // Check for Authorization header
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
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Validate the incoming request data
        $validatedData = $request->validate([
            'id' => 'required|exists:roles,id', // Ensure the role ID exists
            'name' => 'required|string|unique:roles,name,' . $request->id, // Validate role name uniqueness, ignoring current ID
            'role_prefixes' => 'required|string|unique:roles,prefix,' . $request->id, // Validate unique role_prefix, ignoring current ID
            'is_admin_login_permission' => 'nullable|boolean', // Ensure the field is provided and is boolean
        ]);

        // Find the role by ID
        $role = Role::find($request->id);

        // Check if role exists
        if (!$role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        // Update the role with the validated data
        $role->name = $validatedData['name'];
        $role->prefix = $validatedData['role_prefixes'];
        $role->is_admin_login_permission = $validatedData['is_admin_login_permission'];
        $role->save();

        // Return success response with the updated role
        return response()->json([
            'message' => 'Role updated successfully.',
            'role' => $role
        ], 200);
    }


    // public function editrole(Request $request)
    // {
    //     try {
    //         // Check if the Authorization header exists
    //         if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //             return response()->json(['error' => 'Please provide an API token.'], 422);
    //         }

    //         // Retrieve the Authorization header
    //         $authorizationHeader = $request->header('Authorization');

    //         // Check if the header starts with "Bearer "
    //         if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //             return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
    //         }

    //         // Extract the token by removing the "Bearer " prefix
    //         $requestToken = substr($authorizationHeader, 7);

    //         // Check if the token is empty after removing "Bearer "
    //         if (empty($requestToken)) {
    //             return response()->json(['error' => 'Token is missing.'], 422);
    //         }

    //         // Verify the token dynamically (check in the database)
    //         $user = User::where('api_token', $requestToken)->first();

    //         if (!$user) {
    //             return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
    //         }

    //         // Validate the request data
    //         $validatedData = $request->validate([
    //             'id' => 'required|exists:roles,id',
    //             'name' => 'required|string|unique:roles,name,' . $request->id,
    //         'role_prefixes' => 'required|string|unique:roles,prefix,' . $request->id,
    //         ]);

    //         // Check if the role name already exists in the database
    //         $checkRole = Role::where('name', $request->name)->first();

    //         if ($checkRole) {
    //             return response()->json(['error' => 'Role name is already taken'], 400);
    //         }

    //         // Create a new role with the guard_name set to 'sanctum'
    //         $role = Role::create([
    //             'name' => $request->name,
    //             'prefix' => Str::upper($request->role_prefixes),
    //             'created_by' => $user->id,  // The authenticated user's ID is used here
    //             'guard_name' => 'sanctum'   // Set the guard_name to 'sanctum'
    //         ]);

    //         // Return a success response with the role data
    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Role Added',
    //             'data' => $role
    //         ], 201);

    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         // Return validation error response
    //         return response()->json(['error' => $e->errors()], 422);
    //     } catch (\Exception $e) {
    //         // Handle unexpected errors
    //         return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
    //     }
    // }

    public function deleteRole(Request $request)
    {
        // Check for Authorization header
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
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Find the role by ID
        $role = DB::table('roles')->where('id', $request->id)->first();

        // Check if the role exists
        if (!$role) {
            return response()->json(['error' => 'Role not found.'], 404);
        }

        // Check if the role is marked as is_default == 1 (non-deletable)
        if ($role->is_default == 1) {
            return response()->json(['error' => 'Cannot delete this role. It is a default role.'], 403);
        }

        // Check if the role is assigned to any users
        $userCount = DB::table('users')->where('role_id', $role->id)->count();
        if ($userCount > 0) {
            return response()->json([
                'status' => 'false',
                'message' => 'Cannot delete this role as it is assigned to users.'
            ], 400);
        }

        // Proceed with deleting the role if it's not assigned
        try {
            DB::table('roles')->where('id', $role->id)->delete();
            return response()->json(['message' => 'Role deleted successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }

    public function index($id = null)
    {
        try {
            if ($id) {
                // Fetch specific role by ID (including admin)
                $role = Role::where('id', $id)->first();
                return response()->json(['role' => $role]);
            }

            // Fetch all roles including admin
            $roles = Role::where('name', '!=', 'admin')->get();

            return response()->json(['roles' => $roles]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function getallrole(Request $request)
    {
        $roles = Role::where('name', '!=', 'admin')->get();
        return response()->json($roles);
    }

    public function getDefaultRole(Request $request)
    {
        $allowedRoles = ['owner', 'agent', 'company', 'consultancy', 'developer'];

        $roles = Role::whereIn(DB::raw('LOWER(name)'), $allowedRoles)
            ->where('is_default', 1)
            ->orderByRaw("FIELD(LOWER(name), 'owner', 'agent', 'company', 'consultancy', 'developer')")
            ->get();

        return response()->json([
            'status' => true,
            'data' => $roles
        ]);
    }



    public function bulkDeleteRoles(Request $request)
    {
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
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        // Get role IDs from the request
        $roleIds = $request->input('role_ids');
        $selectAll = $request->input('select_all', false);

        // Define protected roles that should not be deleted
        $protectedRoles = ['admin', 'owner', 'agent', 'developer', 'company', 'consultancy'];

        // Initialize arrays to keep track of deleted and skipped roles
        $deletedRoles = [];
        $skippedRoles = [];

        if ($selectAll) {
            // Get all roles that are not protected and not assigned to any users
            $roles = DB::table('roles')
                ->whereNotIn('name', $protectedRoles)
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('users')
                        ->whereColumn('users.role_id', 'roles.id');
                })
                ->get();

            // Delete each role that meets the criteria
            foreach ($roles as $role) {
                DB::table('roles')->where('id', $role->id)->delete();
                $deletedRoles[] = $role->id;
            }
        } else {
            // If specific role IDs are provided, filter for protected and assigned roles
            $invalidRoles = DB::table('roles')
                ->whereIn('id', $roleIds)
                ->whereIn('name', $protectedRoles)
                ->orWhereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('users')
                        ->whereColumn('users.role_id', 'roles.id');
                })
                ->pluck('id')
                ->toArray();

            // Loop through provided IDs and delete if not in invalidRoles
            foreach ($roleIds as $roleId) {
                if (in_array($roleId, $invalidRoles)) {
                    $skippedRoles[] = $roleId;
                    continue;
                }

                $roles = DB::table('roles')
                    ->whereNotIn('name', $protectedRoles)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('users')
                            ->whereColumn('users.role_id', 'roles.id');
                    })
                    ->get();

                // Delete each role that meets the criteria
                foreach ($roles as $role) {
                    DB::table('roles')->where('id', $role->id)->delete();
                    $deletedRoles[] = $role->id;
                }
                $deletedRoles = $roleId;
            }
        }

        // Prepare response message
        $responseMessage = [
            'message' => 'Bulk role deletion completed.',
            'deleted_roles' => $deletedRoles,
            'skipped_roles' => $skippedRoles,
        ];

        // If there are skipped roles due to protection or assignment, update the message
        if (!empty($skippedRoles)) {
            $responseMessage['message'] = 'Some roles could not be deleted due to protection or assignment.';
        }

        return response()->json($responseMessage, 200);
    }

    public function searchRole(Request $request)
    {
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
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $search = $request->search;
        $role = Role::where('name', 'LIKE', '%' . $search . '%')->paginate(5);

        if ($role->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No role found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $role
        ], 200);
    }
}
