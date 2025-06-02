<?php

namespace App\Http\Controllers\Status;

use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Models\Status;
use App\Models\PropertyList;
use App\Http\Controllers\Controller;
use Log;
use Illuminate\Support\Str;


class statuscontroller extends Controller
{

    // public function store(Request $request)
    // {
    //     if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //         return response()->json(['error' => 'Please provide an API token.'], 422);
    //     }

    //     // Retrieve the Authorization header
    //     $authorizationHeader = $request->header('Authorization');

    //     // Check if the header starts with "Bearer "
    //     if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //         return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
    //     }

    //     // Extract the token by removing the "Bearer " prefix
    //     $requestToken = substr($authorizationHeader, 7);

    //     // Check if the token is empty after removing "Bearer "
    //     if (empty($requestToken)) {
    //         return response()->json(['error' => 'Token is missing.'], 422);
    //     }

    //     // Verify the token dynamically (e.g., check in the database)
    //     $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

    //     if (!$tokenExists) {
    //         return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
    //     }

    //     try {
    //         // Validate the request data
    //         $validatedData = $request->validate([
    //             'property_type_id' => 'required|exists:property_types,id',
    //             'name' => 'required|string|unique:status',
    //             'status_display_order' => 'nullable|integer|unique:status'
    //         ]);
    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         // Return validation error response
    //         return response()->json(['error' => $e->errors()], 422);
    //     }

    //     // Determine the status display order
    //     $statusDisplayOrder = $validatedData['status_display_order'];

    //     // If the status_display_order is null, get the maximum value from the table and add 1
    //     if ($statusDisplayOrder === null) {
    //         // Get the maximum display order value and increment it by 1 using raw SQL
    //         $maxOrder = DB::table('status')
    //             ->selectRaw('MAX(CAST(status_display_order AS UNSIGNED)) as max_order')
    //             ->value('max_order');

    //         // If no records exist, set to 1, else increment by 1
    //         $statusDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

    //         // Check for duplicate status_display_order values and increment until a unique value is found
    //         while (Status::where('status_display_order', $statusDisplayOrder)->exists()) {
    //             $statusDisplayOrder++;
    //         }
    //     }

    //     // Create a new status record
    //     $status = Status::create([
    //         'name' => $validatedData['name'],
    //         'property_type_id' => $validatedData['property_type_id'],
    //         'status_display_order' => $statusDisplayOrder,
    //         // 'icon' => $request->has('icon') ? $request->icon : null, // Handle the icon if it exists
    //     ]);

    //     // Return the newly created status as JSON response
    //     return response()->json([
    //         'id' => $status->id,
    //         'name' => $status->name,
    //         'slug' => $status->slug,
    //         'property_type_id' => $status->property_type_id,
    //         'status_display_order' => $status->status_display_order,
    //         'created_at' => $status->created_at,
    //         'updated_at' => $status->updated_at
    //     ], 201);
    // }






    public function store(Request $request)
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

            // Verify the token dynamically (e.g., check in the database)
            $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

            if (!$tokenExists) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }


        try {
            $validatedData = $request->validate([
                'property_type_id' => 'required|array|min:1',
                'property_type_id.*' => 'exists:property_types,id',
                'name' => 'required|string|unique:status,name',
                'status_display_order' => 'nullable|integer|unique:status,status_display_order',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        $statusDisplayOrder = $validatedData['status_display_order'];

        if ($statusDisplayOrder === null) {
            $maxOrder = DB::table('status')
                ->selectRaw('MAX(CAST(status_display_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            $statusDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            while (Status::where('status_display_order', $statusDisplayOrder)->exists()) {
                $statusDisplayOrder++;
            }
        }

        // Generate unique slug
        $baseSlug = Str::slug($validatedData['name']);
        $slug = $baseSlug;
        $i = 1;
        while (Status::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $i++;
        }

        $status = Status::create([
            'name' => $validatedData['name'],
            'property_type_id' => json_encode($validatedData['property_type_id']), // store JSON string
            'status_display_order' => $statusDisplayOrder,
            'slug' => $slug,
        ]);

        return response()->json([
            'id' => $status->id,
            'name' => $status->name,
            'slug' => $status->slug,
            'property_type_id' => $validatedData['property_type_id'], // return original array in response
            'status_display_order' => $status->status_display_order,
            'created_at' => $status->created_at,
            'updated_at' => $status->updated_at,
        ], 201);
    }




    // public function getdatabyId(Request $request)
    // {
    //     try {
    //         // Retrieve the status based on the id
    //         $id = $request->input('id');
    //         $status = Status::with('propertyType')->where('id', $id)->first();

    //         // Check if the status exists
    //         if (!$status) {
    //             return response()->json(['error' => 'Status not found'], 404);
    //         }

    //         // Calculate the property count for this status
    //         $propertyCount = PropertyList::where('property_status_id', $status->id)->count();

    //         // Add property type name to the response
    //         $status->property_type_name = optional($status->propertyType)->name;

    //         // Add property count to the response
    //         $status->propertyCount = $propertyCount;

    //         // Hide unnecessary relationships or attributes (like 'propertyType' if not needed)
    //         $status->makeHidden('propertyType');

    //         // Return the status data along with the property count
    //         return response()->json(['status' => $status], 200);
    //     } catch (\Throwable $th) {
    //         // Handle any exceptions and return an error response
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }


    public function getdatabyId(Request $request)
    {
        try {
            // Retrieve the status based on the id
            $id = $request->input('id');
            $status = Status::where('id', $id)->first();

            // Check if the status exists
            if (!$status) {
                return response()->json(['error' => 'Status not found'], 404);
            }

            // Decode JSON property_type_id to array
            $propertyTypeIds = json_decode($status->property_type_id, true);

            $propertyTypes = [];

            if (is_array($propertyTypeIds) && count($propertyTypeIds) > 0) {
                // Fetch property types with id and name
                $propertyTypesRaw = \DB::table('property_types')
                    ->whereIn('id', $propertyTypeIds)
                    ->select('id', 'name')
                    ->get();

                // Format each property type as required
                foreach ($propertyTypesRaw as $pt) {
                    $propertyTypes[] = [
                        'property_type_id' => $pt->id,
                        'property_type_name' => $pt->name,
                    ];
                }
            }

            // Calculate the property count for this status
            $propertyCount = PropertyList::where('property_status_id', $status->id)->count();

            // Format the response data
            $statusData = [
                'id' => $status->id,
                'status_name' => $status->name,
                'slug' => $status->slug,
                'property_type' => $propertyTypes,  // array of objects
                'status_display_order' => $status->status_display_order,
                'created_at' => $status->created_at->toDateTimeString(),
                'updated_at' => $status->updated_at->toDateTimeString(),
                'propertyCount' => $propertyCount,
            ];

            return response()->json(['status' => $statusData], 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }




    // public function index()
    // {
    //     $statuses = Status::with('propertyType')->get();

    //     $formattedStatuses = [];

    //     foreach ($statuses as $status) {
    //         $assignPropertyCount = PropertyList::where('property_status_id', $status->id)->count();
    //         $formattedStatuses[] = [
    //             'id' => $status->id,
    //             'status_name' => $status->name,
    //             'slug' => $status->slug,
    //             'property_type_id' => $status->property_type_id,
    //             'property_type_name' => optional($status->propertyType)->name,
    //             'status_display_order' => $status->status_display_order,
    //             'created_at' => $status->created_at->toDateTimeString(),
    //             'updated_at' => $status->updated_at->toDateTimeString(),
    //             'propertyCount' => $assignPropertyCount,
    //         ];
    //     }

    //     return response()->json($formattedStatuses);
    // }

    public function index()
    {
        $statuses = Status::all();

        $formattedStatuses = [];

        foreach ($statuses as $status) {
            // Decode JSON string to array of property_type IDs
            $propertyTypeIds = json_decode($status->property_type_id, true);

            $propertyTypes = [];

            if (is_array($propertyTypeIds) && count($propertyTypeIds) > 0) {
                // Fetch property types with id and name
                $propertyTypesRaw = \DB::table('property_types')
                    ->whereIn('id', $propertyTypeIds)
                    ->select('id', 'name')
                    ->get();

                // Format each property type as required
                foreach ($propertyTypesRaw as $pt) {
                    $propertyTypes[] = [
                        'property_type_id' => $pt->id,
                        'property_type_name' => $pt->name,
                    ];
                }
            }

            $assignPropertyCount = PropertyList::where('property_status_id', $status->id)->count();

            $formattedStatuses[] = [
                'id' => $status->id,
                'status_name' => $status->name,
                'slug' => $status->slug,
                'property_type' => $propertyTypes,  // Array of objects with id and name keys
                'status_display_order' => $status->status_display_order,
                'created_at' => $status->created_at->toDateTimeString(),
                'updated_at' => $status->updated_at->toDateTimeString(),
                'propertyCount' => $assignPropertyCount,
            ];
        }

        return response()->json($formattedStatuses);
    }





    // public function update(Request $request)
    // {
    //     if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //         return response()->json(['error' => 'Please provide an API token.'], 422);
    //     }

    //     $authorizationHeader = $request->header('Authorization');

    //     if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //         return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
    //     }

    //     $requestToken = substr($authorizationHeader, 7);

    //     if (empty($requestToken)) {
    //         return response()->json(['error' => 'Token is missing.'], 422);
    //     }

    //     $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

    //     if (!$tokenExists) {
    //         return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
    //     }
    //     try {
    //         // Validate the request data
    //         $validatedData = $request->validate([
    //             'property_type_id' => 'required|exists:property_types,id',
    //             'name' => 'required|string|unique:status,name,' . $request->id,
    //             'status_display_order' => 'nullable|integer|unique:status,status_display_order,' . $request->id,
    //         ]);

    //         $id = $request->id;

    //         try {
    //             // Find the Status by ID
    //             $status = Status::findOrFail($id);
    //         } catch (ModelNotFoundException $e) {
    //             return response()->json(['error' => 'Status with ID ' . $id . ' not found.'], 404);
    //         }
    //         $statusDisplayOrder = $validatedData['status_display_order'];

    //         // If the status_display_order is null, get the maximum value from the table and add 1
    //         if ($statusDisplayOrder === null) {
    //             // Get the maximum display order value and increment it by 1 using raw SQL
    //             $maxOrder = DB::table('status')
    //                 ->selectRaw('MAX(CAST(status_display_order AS UNSIGNED)) as max_order')
    //                 ->value('max_order');

    //             // If no records exist, set to 1, else increment by 1
    //             $statusDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

    //             // Check for duplicate status_display_order values and increment until a unique value is found
    //             while (Status::where('status_display_order', $statusDisplayOrder)->exists()) {
    //                 $statusDisplayOrder++;
    //             }
    //         }

    //         // Update the Status
    //         $updateData = [
    //             'status_display_order' => $statusDisplayOrder,
    //             'name' => $request->name,
    //             'property_type_id' => $request->property_type_id,
    //         ];

    //         $status->update($updateData);

    //         return response()->json($status, 200);

    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         return response()->json(['error' => $e->errors()], 422);
    //     } catch (\Exception $e) {
    //         Log::error('Error updating status: ' . $e->getMessage());
    //         return response()->json(['error' => 'An unexpected error occurred.'], 500);
    //     }
    // }


    public function update(Request $request)
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

        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        try {
            // Validate the request data
            // property_type_id should be an array of existing IDs now
            $validatedData = $request->validate([
                'property_type_id' => 'required|array|min:1',
                'property_type_id.*' => 'exists:property_types,id',
                'name' => 'required|string|unique:status,name,' . $request->id,
                'status_display_order' => 'nullable|integer|unique:status,status_display_order,' . $request->id,
            ]);

            $id = $request->id;

            try {
                // Find the Status by ID
                $status = Status::findOrFail($id);
            } catch (ModelNotFoundException $e) {
                return response()->json(['error' => 'Status with ID ' . $id . ' not found.'], 404);
            }

            $statusDisplayOrder = $validatedData['status_display_order'] ?? null;

            if ($statusDisplayOrder === null) {
                $maxOrder = DB::table('status')
                    ->selectRaw('MAX(CAST(status_display_order AS UNSIGNED)) as max_order')
                    ->value('max_order');

                $statusDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

                while (Status::where('status_display_order', $statusDisplayOrder)->exists()) {
                    $statusDisplayOrder++;
                }
            }

            // Convert property_type_id array to JSON string before storing
            $propertyTypeIdJson = json_encode($validatedData['property_type_id']);

            // Update the Status
            $updateData = [
                'status_display_order' => $statusDisplayOrder,
                'name' => $validatedData['name'],
                'property_type_id' => $propertyTypeIdJson,
            ];

            $status->update($updateData);

            return response()->json($status, 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating status: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }


    public function destroy(Request $request)
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

        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        try {
            $status = Status::findOrFail($request->id);

            if (!$status) {
                return response()->json(['error' => 'Invalid ID'], 404);
            }

            $status->delete();

            return response()->json(['message' => 'Status deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Status not found.'], 404);
        }
    }


    public function bulkDelete(Request $request)
    {

        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (strpos($authorizationHeader, 'Bearer ') !== 0) {
            return response()->json(['error' => 'Invalid token format.'], 422);
        }

        // Extract the token by removing "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        try {
            // Find the builder by ID
            $delete_ids = explode(',', $request->id);

            foreach ($delete_ids as $row) {
                $purpose = Status::findOrFail($row);
                // Delete the builder record
                $purpose->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Status bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'purpose not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }

    // public function searchByName(Request $request)
    // {
    //     // Check for API token
    //     if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //         return response()->json(['error' => 'Please provide an API token.'], 422);
    //     }

    //     $authorizationHeader = $request->header('Authorization');

    //     if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //         return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
    //     }

    //     $requestToken = substr($authorizationHeader, 7);

    //     if (empty($requestToken)) {
    //         return response()->json(['error' => 'Token is missing.'], 422);
    //     }

    //     // Verify API token
    //     $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

    //     if (!$tokenExists) {
    //         return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
    //     }

    //     $validatedData = $request->validate([
    //         'name' => 'required|string|max:255',
    //     ]);

    //     // Retrieve the 'name' query parameter
    //     $searchTerm = $validatedData['name'] ?? '';  // Default to empty string if 'name' is not provided

    //     if (!empty($searchTerm)) {
    //         // If search term is provided, filter purposes based on the search term
    //         $purposes = Status::where('name', 'LIKE', '%' . $searchTerm . '%') // Match anywhere in the name
    //             ->orderBy('name') // Sort alphabetically by name
    //             ->get();

    //         // Sort to ensure that names starting with the search term come first
    //         $purposes = $purposes->sortBy(function ($purpose) use ($searchTerm) {
    //             // Priority: If the name starts with the search term, give it a higher priority (0)
    //             if (strtolower(substr($purpose->name, 0, strlen($searchTerm))) === strtolower($searchTerm)) {
    //                 return 0; // Highest priority
    //             }
    //             return 1; // Default priority for others
    //         });
    //     } else {
    //         // If no search term is provided, return all purposes
    //         $purposes = Status::orderBy('name')->get(); // Return all purposes sorted alphabetically
    //     }

    //     // Add 'propertyCount' and rename 'name' to 'status_name' for each purpose
    //     $purposesWithCount = $purposes->map(function ($purpose) {
    //         $assignPropertyCount = PropertyList::where('property_status_id', $purpose->id)->count();  // Get the property count for each purpose
    //         $purpose->propertyCount = $assignPropertyCount;  // Add the property count to the purpose

    //         // Rename 'name' to 'status_name'
    //         $purpose->status_name = $purpose->name;

    //         // Remove the original 'name' field
    //         unset($purpose->name);

    //         return $purpose;
    //     });

    //     // Ensure the response is in the correct array format
    //     return response()->json($purposesWithCount->values()->toArray());
    // }

    public function searchByName(Request $request)
    {
        // Token validation
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

        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();
        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $searchTerm = $validatedData['name'] ?? '';

        $query = Status::query();
        if (!empty($searchTerm)) {
            $query->where('name', 'LIKE', '%' . $searchTerm . '%');
        }

        $statuses = $query->orderBy('name')->get();

        // Optional priority sorting
        if (!empty($searchTerm)) {
            $statuses = $statuses->sortBy(function ($status) use ($searchTerm) {
                return (strtolower(substr($status->name, 0, strlen($searchTerm))) === strtolower($searchTerm)) ? 0 : 1;
            });
        }

        // Prepare final output
        $formattedStatuses = $statuses->map(function ($status) {
            $propertyCount = PropertyList::where('property_status_id', $status->id)->count();

            // Decode property_type_id (assuming it's stored as JSON array in DB)
            $propertyTypeIds = json_decode($status->property_type_id, true) ?? [];

            // Fetch property types
            $propertyTypes = DB::table('property_types')
                ->whereIn('id', $propertyTypeIds)
                ->select('id as property_type_id', 'name as property_type_name')
                ->get();

            return [
                'id' => $status->id,
                'status_name' => $status->name,
                'slug' => $status->slug,
                'property_type' => $propertyTypes,
                'status_display_order' => $status->status_display_order,
                'created_at' => $status->created_at,
                'updated_at' => $status->updated_at,
                'propertyCount' => $propertyCount,
            ];
        });

        return response()->json($formattedStatuses->values()->toArray());
    }




}
