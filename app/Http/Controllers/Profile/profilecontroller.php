<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\UserDetail;
class profilecontroller extends Controller
{
    public function updateProfile(Request $request)
{
    try {
        // Validate the incoming request data
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id', // Ensure the user ID exists in the database
            'role_id' => 'required|exists:roles,id', // Ensure the role ID exists in the roles table
            'bussiness_name' => 'nullable|string',
            'bussiness_address' => 'nullable|string',
            'bussiness_email' => 'nullable|email',
            'business_phone' => 'nullable|string',
            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'city' => 'nullable|string',
            'pin_code' => 'nullable|string',
            'license_number' => 'nullable|string',
            'alternate_number' => 'nullable|string',
            // Add more validation rules as needed
        ]);

        // Extract user ID and role ID from the validated data
        $userId = $validatedData['user_id'];
        $roleId = $validatedData['role_id'];

        // Check if the provided user is the admin user
        if ($userId == 1) {
            return response()->json(['error' => 'You cannot update the admin user.'], 403);
        }

        // Check if the provided role_id is for an admin role
        if ($roleId == 1) {
            return response()->json(['error' => 'You cannot assign the admin role.'], 403);
        }

        // Check if the user exists
        $user = User::findOrFail($userId);

        // Check if the provided role_id exists in the roles table
        $role = Role::findOrFail($roleId);

        // Update user profile data
        $user->role_id = $roleId;
        $user->save();

        // Save user details in the user_details table
        $userDetail = UserDetail::updateOrCreate(
            ['user_id' => $userId],
            [
                'role_id' => $roleId,
                'bussiness_name' => $validatedData['bussiness_name'],
                'bussiness_address' => $validatedData['bussiness_address'],
                'bussiness_email' => $validatedData['bussiness_email'],
                'business_phone' => $validatedData['business_phone'],
                'country' => $validatedData['country'],
                'state' => $validatedData['state'],
                'city' => $validatedData['city'],
                'pin_code' => $validatedData['pin_code'],
                'license_number' => $validatedData['license_number'],
                'alternate_number' => $validatedData['alternate_number']
            ]
        );

        // Return a success response
        return response()->json(['message' => 'Profile updated successfully and waiting for admin approval', 'user' => $user, 'user_detail' => $userDetail]);
    } catch (ModelNotFoundException $ex) {
        return response()->json(['error' => 'User not found'], 404);
    }
}

public function approveUser(Request $request)
{
    try {
        // Validate the incoming request data
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id', // Ensure the user ID exists in the database
        ]);

        // Extract user ID from the validated data
        $userId = $validatedData['user_id'];
        if($userId == 1){
            return response()->json(['message' => 'Admin can not be approved'], 200);
        }

        // Update the is_approved column for the specified user
        DB::table('users')->where('id', $userId)->update(['isapproved' => 'approved']);

        // Return a success response
        return response()->json(['message' => 'User approved successfully'], 200);
    } catch (ModelNotFoundException $ex) {
        // Return an error response if the user is not found
        return response()->json(['error' => 'User not found'], 404);
    } catch (\Exception $ex) {
        // Return an error response for other exceptions
        return response()->json(['error' => 'Failed to approve user'], 500);
    }
}
// public function rejectuser(Request $request)
// {
//     try {
        
//         // Validate the incoming request data
//         $validatedData = $request->validate([
//             'user_id' => 'required|exists:users,id', // Ensure the user ID exists in the database
//         ]);

//         // Extract user ID from the validated data
//         $userId = $validatedData['user_id'];
//         if($userId == 1){
//             return response()->json(['message' => 'Admin can not be approved'], 200);
//         }

//         // Update the reject column for the specified user
//         DB::table('users')->where('id', $userId)->update(['isapproved' => 'reject']);

//         // Return a success response
//         return response()->json(['message' => 'User rejected successfully'], 200);
//     } catch (ModelNotFoundException $ex) {
//         // Return an error response if the user is not found
//         return response()->json(['error' => 'User not found'], 404);
//     } catch (\Exception $ex) {
//         // Return an error response for other exceptions
//         return response()->json(['error' => 'Failed to approve user'], 500);
//     }
// }





}
