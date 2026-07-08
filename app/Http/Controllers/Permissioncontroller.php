<?php
// PermissionController.php

namespace App\Http\Controllers;

use App\Models\UserPermission;
use Illuminate\Http\Request;
// use App\Models\Permission;
// use App\Models\Role;
use App\Models\User;
use DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Validator;
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



public function removePermission(Request $request)
{
    // Validate incoming request
    $request->validate([
        'role_id' => 'required|exists:roles,id', // Ensure the role ID exists
        'permission_id' => 'required|string', // Ensure the permission name is provided
    ]);

    // Find the role by ID
    $role = Role::findById($request->role_id);

    // Check if role exists
    if (!$role) {
        return response()->json(['message' => 'Role not found'], 404);
    }

    // Check if the role is not "admin"
    // if ($role->name === 'admin') {
    //     return response()->json(['message' => 'You cannot remove permissions from the admin role'], 403);
    // }

    // Find the permission by name
    $permission = Permission::where('id', $request->permission_id)->first();

    // Check if permission exists
    if (!$permission) {
        return response()->json(['message' => 'Permission not found'], 404);
    }

    // Revoke the permission from the role
    $role->revokePermissionTo($permission);

    // Return success response
    return response()->json(['message' => 'Permission removed from role successfully']);
}
    public function deletePermission(Request $request)
    {
        try {
            $id = $request->id;
            permission::find($id)->delete();

            return response()->json(['message' => 'Permission deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function index()
    {
        
        try {
            $permissions = Permission::all();

            return response()->json(['permissions' => $permissions]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

  

    // public function getPermissionsByRole($roleId)
    // {
    //     try {
    //         // Raw query to fetch permissions by role_id
    //         $permissions = DB::table('role_has_permissions')
    //             ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
    //             ->where('role_has_permissions.role_id', $roleId)
    //             ->get(['permissions.id', 'permissions.name']);
    
    //         return response()->json([
    //             'success' => true,
    //             'data' => $permissions
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    


    public function assignDynamicPermissions(Request $request)
{
    $validated = $request->validate([
        'role_id' => 'required|exists:roles,id',
        'model_name' => 'required|string',
        'create' => 'required|boolean',
        'edit' => 'required|boolean',
        'read' => 'required|boolean',
        'delete' => 'required|boolean',
    ]);

    // Use updateOrCreate to either update an existing record or create a new one
    $permission = UserPermission::updateOrCreate(
        [
            'role_id' => $validated['role_id'], // Match these columns
            'model_name' => $validated['model_name'],
        ],
        [
            'create' => $validated['create'],   // Update or set these columns
            'edit' => $validated['edit'],
            'read' => $validated['read'],
            'delete' => $validated['delete'],
        ]
    );

    return response()->json([
        'success' => true,
        'message' => 'Permission assigned successfully.',
        'data' => $permission,
    ]);
}

public function getPermissionsByRole($role_id)
{
    // Validate that the role exists
    $roleExists = Role::where('id', $role_id)->exists();
    if (!$roleExists) {
        return response()->json([
            'success' => false,
            'message' => 'Role not found.',
        ], 404);
    }

    // Fetch permissions for the given role_id
    $permissions = UserPermission::where('role_id', $role_id)->get();

    return response()->json([
        'success' => true,
        'message' => 'Permissions retrieved successfully.',
        'data' => $permissions,
    ]);
}


public function getModelNames()
{
    // List of model names
    $modelNames = [
        'Dashboard',
        'Users',
        'Roles',
        'Permission',
        'Listings',
        'Purpose',
        'Properties',
        'Property Type',
        'Status',
        'Location',
        'Amenities',
        'Project',
        'Developer',
        'Custom Field',
        'Ticket',
        'Icon Library',
        'CMS',
        'Review',
        'FAQ',
        'Help',
        'Subscribe'
    ];

    // Return the model names as a JSON response
    return response()->json([
        'message' => 'Model names retrieved successfully',
        'model_names' => $modelNames
    ], 200);
}




    
    // public function assignPermission(Request $request)
    // {
    //     $role = Role::findByName('agent');
    //     $permission = Permission::where('name', 'creates category')->first();
    //     // $role->revokePermissionTo('create category');
    //     $role->givePermissionTo($permission);

    //     // $user = User::find(9); // Assuming you have the user ID
    //     // $user->givePermissionTo($permission);

    //     // // Validate the request data
    //     // $request->validate([
    //     //     'role_name' => 'required|string',
    //     //     'permission_name' => 'required|string',
    //     //     'assign_to' => 'required|string', // Can be 'role' or 'user'
    //     //     'assign_id' => 'required|integer', // ID of the role or user to assign permission to
    //     // ]);

    //     // // Find the role or user to assign the permission to
    //     // $assignTo = $request->input('assign_to');
    //     // // $assignId = $request->input('assign_id');

    //     // if ($assignTo === 'role') {
    //     //     $assignable = Role::findOrFail($assignId);
    //     // } elseif ($assignTo === 'user') {
    //     //     $assignable = User::findOrFail($assignId); // Assuming you have a User model
    //     // } else {
    //     //     return response()->json(['error' => 'Invalid assign_to value'], 400);
    //     // }

    //     // // Find the permission
    //     // $permissionName = $request->input('permission_name');
    //     // $permission = Permission::findByName($permissionName);

    //     // // Assign the permission
    //     // $assignable->givePermissionTo($permission);

    //     // return response()->json(['message' => 'Permission assigned successfully'], 200);
    // }


    // public function getPermissions()
    // {
    //     $permissions = Permission::all();
    //     return response()->json($permissions);
    // }

    // public function roleGet()
    // {
    //     $role = Role::where('name', 'user')->first();
    //     $role->permissions()->attach([1, 3]);
    //     return response()->json($role);
    // }

    // public function permissionAttach()
    // {
        
    //     $role = Role::where('name', 'agent')->first();
    //     $role->permissions()->sync([2,3]);
    //     return response()->json($role);
    // }

    // public function allUsers()
    // {
    //     $users = User::all();
    //     return response()->json($users);
    // }

    // public function adminAssignRole(Request $request)
    // {
    //     // Validate the incoming request data
    //     $request->validate([
    //         'user_id' => 'required|exists:users,id',
    //         'role_id' => 'required|exists:roles,id',
    //     ]);

    //     // Find the user
    //     $user = User::findOrFail($request->user_id);

    //     // Find the role
    //     $role = Role::findOrFail($request->role_id);

    //     // Attach the role to the user
    //     $user->roles()->attach($role);
    //     $user->update(['role_id' => $role->id]);

    //     // Optionally, you can provide feedback to the user
    //     return response()->json(['success' => 'Role assigned successfully'], 200);
    // }

//     public function deleterole(Request $request)
// {
//     $userId = $request->userid; // Assuming you have a user with ID 9
//     $user = User::find($userId);
//     if (!$user) {
//        return response()->json(['message'=>'User not found.']);
//     }

//     $roleId = $user->role_id;

//     $role = Role::find($roleId);

//     if (!$role) {
//         dd("Role not found.");
//     }

//     $hasPermission = $user->hasPermission('delete_role');

//     if ($hasPermission) {
//         return response()->json(["success"=>"User with ID $userId can delete role."]);
//     } else {
//         return response()->json(["success"=>"User with ID $userId cannot delete role."]);
//     }
// }

}    