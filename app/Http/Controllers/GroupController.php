<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Groupname;
use App\Models\CustomField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    // public function index(Request $request)
    // {
    //     if ($request->has('id')) {
    //         $group = Groupname::find($request->id);

    //         if (!$group) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Group not found'
    //             ], 404);
    //         }

    //         return response()->json([
    //             'status' => 'success',
    //             'messages' => 'Groups detail get successful',
    //             'data' => $group
    //         ], 200);
    //     }
    //     // Fetch groups from the database
    //     $groups = Groupname::all();
    //     // Return the groups as a JSON response
    //     return response()->json([
    //         'status' => 'success',
    //         'messages' => 'Groups detail get successful',
    //         'data' => $groups
    //     ], 200);
    // }
    public function index(Request $request)
    {
        if ($request->has('id')) {
            $group = Groupname::find($request->id);

            if (!$group) {
                return response()->json([
                    'status' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            // Count the number of custom fields associated with the group
            $customFieldCount = CustomField::where('group_id', $request->id)->count();

            // Convert group to an array and add the custom field count
            $groupData = $group->toArray();
            $groupData['custom_field_count'] = $customFieldCount;

            return response()->json([
                'status' => 'success',
                'message' => 'Group detail fetched successfully',
                'data' => $groupData // Include the custom field count inside the group object
            ], 200);
        }

        // Fetch all groups
        $groups = Groupname::all();

        // Add custom field counts to each group
        $groupsWithCounts = $groups->map(function ($group) {
            // Count the number of custom fields associated with the group
            $customFieldCount = CustomField::where('group_id', $group->id)->count();

            // Convert group to an array and append the custom field count
            $groupData = $group->toArray();
            $groupData['custom_field_count'] = $customFieldCount;

            return $groupData; // Return the modified group data
        });

        // Return the response with all groups and their custom field counts
        return response()->json([
            'status' => 'success',
            'message' => 'Groups detail fetched successfully',
            'data' => $groupsWithCounts
        ], 200);
    }

    public function createGroup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|unique:group_name,group_name'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Create a new group in the database
        $group = Groupname::create([
            'group_name' => $request->group_name,
        ]);
        // Return the created group as a JSON response
        return response()->json([
            'status' => true,
            'message' => "Custom group created",
            'data' => $group
        ]);
    }

    public function updateGroup($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|unique:group_name,group_name,' . $id
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Fetch the group by ID
        $group = Groupname::find($id);

        // Check if the group exists
        if (!$group) {
            return response()->json([
                'status' => false,
                'message' => 'Group not found'
            ], 404);
        }

        // Update the group in the database
        $group->group_name = $request->group_name;
        $group->save();

        // Return the updated group as a JSON response
        return response()->json([
            'status' => true,
            'message' => 'Group updated successfully',
            'data' => $group
        ]);
    }

    public function deleteGroup($id)
    {
        // Fetch the group by ID
        $group = Groupname::find($id);

        // Check if the group exists
        if (!$group) {
            return response()->json([
                'status' => false,
                'message' => 'Group not found'
            ], 404);
        }

        // Delete the group from the database
        $group->delete();

        // Return a success message as a JSON response
        return response()->json([
            'status' => true,
            'message' => 'Group deleted successfully'
        ]);
    }

    public function checkUniqueGroupName(Request $request)
    {
        // Validate request input
        $request->validate([
            'group_name' => 'required|string|max:255',
        ]);

        // Check if the group name already exists
        $exists = Groupname::where('group_name', $request->group_name)->exists();

        if ($exists) {
            return response()->json([
                'status' => false,
                'message' => 'Group name already exists'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'message' => 'Group name is available'
        ], 200);
    }


    public function bulkDeleteGroups(Request $request)
    {
        $request->validate([
            'group_ids' => 'required|array',
            'group_ids.*' => 'integer|exists:group_name,id', // correct table name
        ]);

        DB::beginTransaction();

        try {
            $groups = Groupname::whereIn('id', $request->group_ids)->get();

            if ($groups->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'No groups found to delete.',
                ], 404);
            }

            foreach ($groups as $group) {
                $group->delete(); // This allows triggering model events like deleting()
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => $groups->count() . ' group(s) deleted successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete groups.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
