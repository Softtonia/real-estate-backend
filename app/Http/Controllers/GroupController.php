<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CustomFieldGroup;
use App\Models\CustomField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;

class GroupController extends Controller
{
    // List all groups
    public function index(Request $request)
    {
        try {
            $groups = CustomFieldGroup::with([
                'fields' => function ($query) {
                    $query->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc');
                },
                'fields.options',
                'fields.repeaters.options',
                'fields.locationRules',
                'fields.conditions.taxonomy',
                'fields.conditions.taxonomyTerm',
            ])
                ->withCount(['fields as custom_field_count'])
                ->orderBy('id', 'desc')
                ->get(['id', 'group_name', 'group_slug']);

            $groupsWithFields = $groups->map(function ($group) {
                $groupData = $group->toArray();

                $groupData['custom_field_count'] = $group->custom_field_count;
                $groupData['custom_fields'] = $groupData['fields'] ?? [];

                unset($groupData['fields']);

                return $groupData;
            });

            return response()->json([
                'status' => true,
                'message' => 'Custom field groups fetched successfully.',
                'data' => $groupsWithFields
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Create a new group
    public function createGroup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|unique:custom_field_groups,group_name'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $group = CustomFieldGroup::create([
            'group_name' => $request->group_name,
            'group_slug' => Str::slug($request->group_name),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Custom group created',
            'data' => $group
        ]);
    }

    // Update a group
    public function updateGroup($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|unique:custom_field_groups,group_name,' . $id
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $group = CustomFieldGroup::find($id);

        if (!$group) {
            return response()->json([
                'status' => false,
                'message' => 'Group not found'
            ], 404);
        }

        $group->update([
            'group_name' => $request->group_name,
            'group_slug' => Str::slug($request->group_name),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Group updated successfully',
            'data' => $group
        ]);
    }

    // Delete a group
    public function deleteGroup($id)
    {
        $group = CustomFieldGroup::find($id);

        if (!$group) {
            return response()->json([
                'status' => false,
                'message' => 'Group not found'
            ], 404);
        }

        $group->delete();

        return response()->json([
            'status' => true,
            'message' => 'Group deleted successfully'
        ]);
    }

    // Check if group name is unique
    public function checkUniqueGroupName(Request $request)
    {
        $request->validate([
            'group_name' => 'required|string|max:255',
        ]);

        $exists = CustomFieldGroup::where('group_name', $request->group_name)->exists();

        return response()->json([
            'status' => !$exists,
            'message' => $exists ? 'Group name already exists' : 'Group name is available'
        ]);
    }

    // Bulk delete groups
    public function bulkDeleteGroups(Request $request)
    {
        $request->validate([
            'group_ids' => 'required|array',
            'group_ids.*' => 'integer|exists:custom_field_groups,id',
        ]);

        DB::beginTransaction();

        try {
            $groups = CustomFieldGroup::whereIn('id', $request->group_ids)->get();

            if ($groups->isEmpty()) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'No groups found to delete.',
                ], 404);
            }

            foreach ($groups as $group) {
                $group->delete();
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

    // Search groups by name
    public function searchByGroupName(Request $request)
    {
        try {
            $keyword = $request->input('group_name');

            if (!$keyword) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please provide a group_name keyword to search.'
                ], 400);
            }

            $groups = CustomFieldGroup::where('group_name', 'like', "%$keyword%")->get();

            if ($groups->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No groups found matching the search keyword.'
                ], 200);
            }

            $groupsWithCounts = $groups->map(function ($group) {
                $customFieldCount = CustomField::where('custom_field_group_id', $group->id)->count();

                $groupData = $group->toArray();
                $groupData['custom_field_count'] = $customFieldCount;

                return $groupData;
            });

            return response()->json([
                'status' => true,
                'message' => 'Search results fetched successfully',
                'data' => $groupsWithCounts
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to search custom field groups.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Export groups as CSV
    public function exportGroups()
    {
        try {
            $groups = CustomFieldGroup::orderBy('id', 'asc')
                ->get(['id', 'group_name', 'group_slug', 'created_at', 'updated_at']);

            if ($groups->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No groups found to export.',
                ], 200);
            }

            $fileName = 'groups_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"$fileName\"",
            ];

            $callback = function () use ($groups) {
                $file = fopen('php://output', 'w');

                /*
                |--------------------------------------------------------------------------
                | CSV Format:
                |--------------------------------------------------------------------------
                | 0 = S.No
                | 1 = Group Name
                |
                | Import Key column removed.
                | S.No will be used for row-wise update during import.
                |--------------------------------------------------------------------------
                */

                fputcsv($file, [
                    'S.No',
                    'Group Name',
                ]);

                $serialNumber = 1;

                foreach ($groups as $group) {
                    fputcsv($file, [
                        $serialNumber,
                        $group->group_name,
                    ]);

                    $serialNumber++;
                }

                fclose($file);
            };

            return Response::stream($callback, 200, $headers);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to export groups.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Import groups from CSV
    public function importGroups(Request $request)
    {
        $handle = null;
        $transactionStarted = false;

        try {
            $validator = Validator::make($request->all(), [
                'csv_file' => 'required|file|mimes:csv,txt|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $file = $request->file('csv_file');
            $path = $file->getRealPath();
            $handle = fopen($path, 'r');

            if (!$handle) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unable to read CSV file.',
                ], 400);
            }

            $header = fgetcsv($handle);

            if (!$header) {
                fclose($handle);

                return response()->json([
                    'status' => false,
                    'message' => 'CSV file is empty.',
                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | Expected CSV Format:
            |--------------------------------------------------------------------------
            | 0 = S.No
            | 1 = Group Name
            |
            | Important:
            | Group name se existing record find nahi hoga.
            | S.No ke basis par existing group find hoga.
            |--------------------------------------------------------------------------
            */

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $conflicts = 0;
            $rowNumber = 1;

            DB::beginTransaction();
            $transactionStarted = true;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                $serialNumber = trim($row[0] ?? '');
                $groupName = trim($row[1] ?? '');

                if (empty($groupName)) {
                    $skipped++;
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | S.No required for update
                |--------------------------------------------------------------------------
                | Agar S.No valid hai, to us row number ke basis par existing record update hoga.
                | Example:
                | S.No 1 = first group by id asc
                | S.No 2 = second group by id asc
                |--------------------------------------------------------------------------
                */

                $existingGroup = null;

                if (!empty($serialNumber) && is_numeric($serialNumber)) {
                    $serialNumber = (int) $serialNumber;

                    if ($serialNumber > 0) {
                        $existingGroup = CustomFieldGroup::orderBy('id', 'asc')
                            ->skip($serialNumber - 1)
                            ->first();
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Existing group update case
                |--------------------------------------------------------------------------
                */

                if ($existingGroup) {
                    $newSlug = Str::slug($groupName);

                    $nameConflict = CustomFieldGroup::where('group_name', $groupName)
                        ->where('id', '!=', $existingGroup->id)
                        ->exists();

                    $slugConflict = CustomFieldGroup::where('group_slug', $newSlug)
                        ->where('id', '!=', $existingGroup->id)
                        ->exists();

                    if ($nameConflict || $slugConflict) {
                        $conflicts++;
                        $skipped++;
                        continue;
                    }

                    $existingGroup->update([
                        'group_name' => $groupName,
                        'group_slug' => $newSlug,
                    ]);

                    $updated++;
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | New group create case
                |--------------------------------------------------------------------------
                | Agar S.No ke basis par existing group nahi mila,
                | to new group create hoga.
                |--------------------------------------------------------------------------
                */

                $newSlug = Str::slug($groupName);

                $nameExists = CustomFieldGroup::where('group_name', $groupName)->exists();
                $slugExists = CustomFieldGroup::where('group_slug', $newSlug)->exists();

                if ($nameExists || $slugExists) {
                    $conflicts++;
                    $skipped++;
                    continue;
                }

                CustomFieldGroup::create([
                    'group_name' => $groupName,
                    'group_slug' => $newSlug,
                ]);

                $inserted++;
            }

            fclose($handle);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Import completed successfully.',
                'data' => [
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'conflicts' => $conflicts,
                ],
            ], 200);
        } catch (\Exception $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }

            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }

            return response()->json([
                'status' => false,
                'message' => 'Failed to import groups.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}