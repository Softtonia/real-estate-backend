<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Models\User;
use DB;
use Illuminate\Http\Request;
use App\Models\Property;
use App\Models\PropertyList;
use Illuminate\Support\Facades\Log;
use Storage;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Str;

class PropertyController extends Controller
{

    public function store(Request $request)
    {
        // dd('hello');
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
            $validatedData = $request->validate([
                'name' => 'required|string|unique:properties',
                'display_properties_order' => 'nullable|integer|unique:properties',
            ]);

            // Determine `display_properties_order` if not provided
            $displayPropertiesOrder = $request->input('display_properties_order');

            if ($displayPropertiesOrder === null) {
                $maxOrder = DB::table('properties')
                    ->selectRaw('MAX(CAST(display_properties_order AS UNSIGNED)) as max_order')
                    ->value('max_order');

                $displayPropertiesOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

                while (Property::where('display_properties_order', $displayPropertiesOrder)->exists()) {
                    $displayPropertiesOrder++;
                }
            }

            // Store image path only
            if ($request->hasFile('property_image')) {
                $file = $request->file('property_image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
                $file->move(public_path('uploads/properties'), $name); // Move file to correct folder
                $profile_image = 'uploads/properties/' . $name; // Store only relative path
            } elseif (isset($request->icon) && !empty($request->icon)) {
                $profile_image = $request->input('icon');
            } else {
                $profile_image = null;
            }

            // Create a new property record
            $property = Property::create([
                'name' => $request->input('name'),
                'display_properties_order' => $displayPropertiesOrder,
                'property_image' => $profile_image, // Store only the relative path
            ]);

            // Return the newly created property as JSON response
            return response()->json([
                'id' => $property->id,
                'name' => $property->name,
                'slug' => $property->slug,
                'image' => $property->property_image, // Returns only the path
                'display_properties_order' => $property->display_properties_order,
                'created_at' => $property->created_at,
                'updated_at' => $property->updated_at,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    public function index()
    {
        $properties = Property::get();

        foreach ($properties as $row) {
            // Append full image path if an image exists
            if (!empty($row->property_image)) {
                $row->property_image = url($row->property_image);
            }

            // Count assigned properties
            $assignPropertyCount = PropertyList::where('property_id', $row->id)->count();
            $row->propertyCount = $assignPropertyCount;
        }

        return response()->json($properties);
    }


    public function update(Request $request)
    {
        // Check for Authorization header
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        $authorizationHeader = $request->header('Authorization');

        // Validate Bearer token format
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);

        // Ensure token is not empty
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token in the database
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        try {
            $id = $request->id;

            // Validate the request data
            $validatedData = $request->validate([
                'id' => ['required', Rule::exists('properties', 'id')],
                'name' => ['required', 'string', 'max:255'],
                'display_properties_order' => [
                    'nullable',
                    'integer',
                    Rule::unique('properties', 'display_properties_order')->ignore($id),
                ],
                'property_image' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:10240', // Max 10MB
            ]);

            // Find the property
            $property = Property::findOrFail($id);

            // Handle property image upload
            if ($request->hasFile('property_image')) {
                $file = $request->file('property_image');
                $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('uploads/properties'), $fileName);
                $profileImage = 'uploads/properties/' . $fileName; // Store only relative path
            } elseif ($request->filled('icon')) {
                $profileImage = $request->input('icon');
            } else {
                $profileImage = $property->property_image; // Keep the existing image if none is uploaded
            }

            // Determine `display_properties_order` if not provided
            $displayPropertiesOrder = $request->input('display_properties_order');

            if ($displayPropertiesOrder === null) {
                // Find the greatest number in `display_properties_order`
                $maxOrder = DB::table('properties')
                    ->selectRaw('MAX(CAST(display_properties_order AS UNSIGNED)) as max_order')
                    ->value('max_order');

                // If no records exist, set to 1, else increment by 1
                $displayPropertiesOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

                // Ensure unique value (optional, can skip if `unique` validation suffices)
                while (Property::where('display_properties_order', $displayPropertiesOrder)->exists()) {
                    $displayPropertiesOrder++;
                }
            }

            // Update the property
            $property->update([
                'name' => $validatedData['name'],
                'display_properties_order' => $displayPropertiesOrder,
                'property_image' => $profileImage, // Store only the relative path
            ]);

            // Return the updated property
            return response()->json([
                'id' => $property->id,
                'name' => $property->name,
                'slug' => $property->slug,
                'image' => $property->property_image, // Shows only the relative path
                'display_properties_order' => $property->display_properties_order,
                'created_at' => $property->created_at,
                'updated_at' => $property->updated_at,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);

        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while updating the property.'], 500);
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
            $id = $request->id;
            $property = Property::find($id);

            if (!$property) {
                return response()->json(['error' => 'Property not found'], 404);
            }

            $property->delete();
            return response()->json(['message' => 'Property deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete property'], 500);
        }
    }


    // public function getPropertyAndType(Request $request)
    // {
    //     $id = $request->id;
    //     $property = Property::with('propertytype')->find($id);
    //     if (!$property) {
    //         return response()->json(['message' => 'Property not found'], 404);
    //     }
    //     return response()->json($property);
    // }
    public function getPropertyAndType(Request $request)
    {
        // Retrieve the property based on the id
        $id = $request->id;
        $property = Property::with('propertytype')->find($id);

        // Check if the property exists
        if (!$property) {
            return response()->json(['message' => 'Property not found'], 404);
        }

        // Calculate the property count for this specific property
        $propertyCount = PropertyList::where('property_id', $property->id)->count();

        // Add propertyCount to the property object
        $property->propertyCount = $propertyCount;

        // Return the property data with the property count
        return response()->json($property);
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
                $property = Property::findOrFail($row);
                // Delete the builder record
                $property->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Property bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'property not found'], 404);
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
    //         $purposes = Property::where('name', 'LIKE', '%' . $searchTerm . '%') // Match anywhere in the name
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
    //         $purposes = Property::orderBy('name')->get(); // Return all purposes sorted alphabetically
    //     }

    //     // Add 'propertyCount' to each purpose
    //     $purposesWithCount = $purposes->map(function ($purpose) {
    //         $assignPropertyCount = PropertyList::where('property_id', $purpose->id)->count();  // Get the property count for each purpose
    //         $purpose->propertyCount = $assignPropertyCount;  // Add the property count to the purpose
    //         return $purpose;
    //     });

    //     // Ensure the response is in the correct array format
    //     return response()->json($purposesWithCount->values()->toArray());
    // }



    public function searchByName(Request $request)
    {
        // Check for API token
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['status' => false, 'error' => 'Please provide an API token.'], 422);
        }

        $authorizationHeader = $request->header('Authorization');

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['status' => false, 'error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['status' => false, 'error' => 'Token is missing.'], 422);
        }

        // Verify API token
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['status' => false, 'error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Retrieve the 'name' query parameter
        $searchTerm = $validatedData['name'] ?? '';

        // Get pagination parameters
        $perPage = $request->input('per_page', 1);
        $page = $request->input('page', 1);

        // Create base query
        $query = Property::query();

        if (!empty($searchTerm)) {
            $query->where('name', 'LIKE', '%' . $searchTerm . '%')
                ->orderByRaw("CASE
                  WHEN name LIKE '" . $searchTerm . "%' THEN 0
                  ELSE 1
              END");
        }

        $query->orderBy('name');

        // Get paginated results and append all query parameters
        $paginatedPurposes = $query->paginate($perPage, ['*'], 'page', $page)
            ->appends($request->query());

        // Add 'propertyCount' to each purpose
        $purposesWithCount = $paginatedPurposes->getCollection()->map(function ($purpose) {
            $assignPropertyCount = PropertyList::where('property_id', $purpose->id)->count();
            $purpose->propertyCount = $assignPropertyCount;
            return $purpose;
        });

        $paginatedPurposes->setCollection($purposesWithCount);

        // Return the formatted response
        return response()->json([
            'status' => true,
            'data' => $paginatedPurposes
        ]);
    }

}
