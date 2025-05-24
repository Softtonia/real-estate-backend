<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RolePrefixReapeater;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class SystemController extends Controller
{
    public function GetRolePrefixRepeater(Request $request)
    {
        // Check if a specific `id` is provided
        if ($request->has('id')) {
            // Fetch a single RolePrefixRepeater by id with associated role and creator
            $singleRolePrefix = RolePrefixReapeater::with(['role', 'createdBy'])->find($request->id);

            // Return error response if record is not found
            if (!$singleRolePrefix) {
                return response()->json([
                    'status' => 'false',
                    'message' => 'Record Not Found'
                ], 404);
            }

            // Return the single record with associated data
            return response()->json([
                'status' => 'success',
                'role_prefix_repeater' => $singleRolePrefix
            ], 200);
        }

        // Fetch all role prefix repeater data with associated role and creator
        $rolePrefixRepeaters = RolePrefixReapeater::with(['role', 'createdBy'])->get();

        // Return all records
        return response()->json([
            'status' => 'success',
            'role_prefix_repeaters' => $rolePrefixRepeaters
        ], 200);
    }


    public function CreateRolePrefixRepeater(Request $request)
    {
        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'prefix' => 'required|array',
            'prefix.*' => 'string',
            'role_ids' => 'required|array',
            'role_ids.*' => 'required|array',
            'role_ids.*.*' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Insert each prefix with corresponding role IDs into the database
        $data = [];
        foreach ($request->prefix as $index => $prefix) {
            $rolePrefixSlug = Str::slug($prefix);

            // Loop through each role ID for the current prefix
            foreach ($request->role_ids[$index] as $roleId) {
                $data[] = [
                    'role_id' => $roleId,
                    'role_prefix_slug' => $rolePrefixSlug,
                    'role_prefix' => $prefix,
                    'created_by' => Auth::user()->fullname, // assuming you track the creator's ID
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Bulk insert into the database
        RolePrefixReapeater::insert($data);

        return response()->json([
            'status' => true,
            'message' => 'Role prefix records created successfully'
        ], 201);
    }

    public function DeleteRolePrefixRepeater($id)
    {
        // Find and delete the role prefix repeater record
        $rolePrefixRepeater = RolePrefixReapeater::find($id);
        if (!$rolePrefixRepeater) {
            return response()->json([
                'status' => false,
                'message' => 'Role prefix repeater record not found'
            ], 400);
        }

        $rolePrefixRepeater->delete();

        return response()->json([
            'status' => true,
            'message' => 'Role prefix repeater record deleted successfully'
        ], 200);
    }

    public function UpdateRolePrefixRepeater($id, Request $request)
    {
        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|integer',
            'role_prefix' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find and update the role prefix repeater record
        $rolePrefixRepeater = RolePrefixReapeater::find($id);
        if (!$rolePrefixRepeater) {
            return response()->json([
                'status' => false,
                'message' => 'Role prefix repeater record not found'
            ], 400);
        }

        $rolePrefixRepeater->update([
            'role_id' => $request->role_id,
            'role_prefix_slug' => Str::slug($request->role_prefix),
            'role_prefix' => $request->role_prefix,
            'created_by' => Auth::user()->fullname,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Role prefix repeater record updated successfully'
        ], 200);
    }
}
