<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PropertyType;
use App\Models\PropertyList;
use App\Models\User;
use App\Models\Status;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Storage;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

class Propertytypecontroller extends Controller
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
    //             'name' => 'required|string|max:255|unique:property_types,name',
    //             'display_property_types_order' => 'nullable|string|max:255|unique:property_types,display_property_types_order',
    //             'property_id' => 'required|exists:properties,id',
    //             'image' => 'nullable'
    //         ]);

    //         // Determine `display_property_types_order` if not provided
    //         $displayPropertyTypesOrder = $request->input('display_property_types_order');

    //         if ($displayPropertyTypesOrder === null) {
    //             // Find the greatest number in `display_property_types_order`
    //             $maxOrder = DB::table('property_types')
    //                 ->selectRaw('MAX(CAST(display_property_types_order AS UNSIGNED)) as max_order')
    //                 ->value('max_order');
    //             // If no records exist, set to 1, else increment by 1
    //             $displayPropertyTypesOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

    //             // Ensure unique value (optional, can skip if `unique` validation suffices)
    //             while (DB::table('property_types')->where('display_property_types_order', $displayPropertyTypesOrder)->exists()) {
    //                 $displayPropertyTypesOrder++;
    //             }
    //         }

    //         if ($request->hasFile('image')) {
    //             $file = $request->file('image');
    //             $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
    //             $file->move(public_path('uploads/property_types'), $name); // Move file to correct folder
    //             // Generate the URL in the required format
    //             $profile_image = url('uploads/property_types/' . $name); // Correct path for public access
    //         } elseif (isset($request->image) && !empty($request->image)) {
    //             $profile_image = $request->input('image');
    //         } else {
    //             // If no icon is uploaded and 'icon' is not set or is empty, set profile_image to null
    //             $profile_image = null; // Make sure it's properly set to null
    //         }

    //         // Create a new property type record
    //         $propertyType = PropertyType::create([
    //             'name' => $validatedData['name'],
    //             'display_property_types_order' => $displayPropertyTypesOrder,
    //             'image' => $profile_image, // Save the image path in the database
    //             'property_id' => $validatedData['property_id'], // Set the property_id
    //         ]);

    //         // Return the created property type as JSON response
    //         return response()->json($propertyType, 201);
    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         // Return validation error response
    //         return response()->json(['error' => $e->errors()], 422);
    //     } catch (\Throwable $th) {
    //         // Handle any exceptions and return an error response
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }



    public function store(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve and validate the Authorization header
        $authorizationHeader = $request->header('Authorization');

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the API token in the database
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        try {
            // Validate the request data
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:property_types,name',
                'display_property_types_order' => 'nullable|string|max:255|unique:property_types,display_property_types_order',
                'property_id' => 'required|exists:properties,id',
                'image' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:10240', // Max 10MB
            ]);

            // Determine `display_property_types_order` if not provided
            $displayPropertyTypesOrder = $request->input('display_property_types_order');

            if ($displayPropertyTypesOrder === null) {
                $maxOrder = DB::table('property_types')
                    ->selectRaw('MAX(CAST(display_property_types_order AS UNSIGNED)) as max_order')
                    ->value('max_order');

                $displayPropertyTypesOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

                while (DB::table('property_types')->where('display_property_types_order', $displayPropertyTypesOrder)->exists()) {
                    $displayPropertyTypesOrder++;
                }
            }

            // Handle property image upload and store only the relative path
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $filePath = 'uploads/property_types/' . $fileName; // Store only relative path
                $file->move(public_path('uploads/property_types'), $fileName);
            } else {
                $filePath = null;
            }

            // Create a new property type record
            $propertyType = PropertyType::create([
                'name' => $validatedData['name'],
                'display_property_types_order' => $displayPropertyTypesOrder,
                'image' => $filePath, // Store only relative path
                'property_id' => $validatedData['property_id'],
            ]);

            // Return the created property type as JSON response
            return response()->json([
                'id' => $propertyType->id,
                'name' => $propertyType->name,
                'display_property_types_order' => $propertyType->display_property_types_order,
                'image' => $propertyType->image, // Shows only relative path
                'property_id' => $propertyType->property_id,
                'created_at' => $propertyType->created_at,
                'updated_at' => $propertyType->updated_at,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // public function index()
    // {
    //     try {
    //         // Get all property types with associated property details
    //         $propertyTypes = PropertyType::with('property')->get();

    //         // Initialize an array to store property type data
    //         $propertyTypesData = [];

    //         // Iterate over each property type and extract id, name, slug, display order, property id, property name, and image
    //         foreach ($propertyTypes as $propertyType) {
    //             $propertyName = $propertyType->property ? $propertyType->property->name : null;
    //             $assignPropertyCount = PropertyList::where('property_type_id', $propertyType->id)->count();

    //             $propertyTypesData[] = [
    //                 'id' => $propertyType->id,
    //                 'name' => $propertyType->name,
    //                 'slug' => $propertyType->slug,
    //                 'display_property_types_order' => $propertyType->display_property_types_order,
    //                 'property_id' => optional($propertyType->property)->id,
    //                 'property_name' => $propertyName,
    //                 'image' => $propertyType->image,
    //                 'propertyCount' => $assignPropertyCount,
    //             ];
    //         }

    //         // Return the property type data as JSON response
    //         return response()->json($propertyTypesData);
    //     } catch (\Throwable $th) {
    //         // Handle any exceptions and return an error response
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    public function index()
    {
        try {
            // Get all property types with associated property details
            $propertyTypes = PropertyType::with('property')->get();

            // Initialize an array to store property type data
            $propertyTypesData = [];

            // Iterate over each property type and extract details
            foreach ($propertyTypes as $propertyType) {
                $propertyName = $propertyType->property ? $propertyType->property->name : null;
                $assignPropertyCount = PropertyList::where('property_type_id', $propertyType->id)->count();

                $propertyTypesData[] = [
                    'id' => $propertyType->id,
                    'name' => $propertyType->name,
                    'slug' => $propertyType->slug,
                    'display_property_types_order' => $propertyType->display_property_types_order,
                    'property_id' => optional($propertyType->property)->id,
                    'property_name' => $propertyName,
                    'image' => $propertyType->image ? url($propertyType->image) : null, // Convert relative path to full URL
                    'propertyCount' => $assignPropertyCount,
                ];
            }

            // Return the property type data as JSON response
            return response()->json($propertyTypesData);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function getdatabyId(Request $request)
    {
        try {
            $id = $request->id;

            // Get the property type by ID with associated property details
            $propertyType = PropertyType::with('property')->find($id);

            // Check if the property type exists
            if (!$propertyType) {
                return response()->json(['error' => 'Property type not found'], 404);
            }

            // Calculate the property count for this property type
            $propertyCount = PropertyList::where('property_type_id', $propertyType->id)->count();

            // Extract necessary data
            $propertyName = $propertyType->property ? $propertyType->property->name : null;
            $propertyTypeData = [
                'id' => $propertyType->id,
                'name' => $propertyType->name,
                'slug' => $propertyType->slug,
                'display_property_types_order' => $propertyType->display_property_types_order,
                'property_name' => $propertyName,
                'property_id' => $propertyType->property_id,
                'image' => $propertyType->image ? url($propertyType->image) : null, // Convert to full URL
                'propertyCount' => $propertyCount,
            ];

            // Remove the image key if its value is null
            if (empty($propertyTypeData['image']) || $propertyType->image === "null") {
                unset($propertyTypeData['image']);
            }

            // Return the property type data as JSON response
            return response()->json($propertyTypeData);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function update(Request $request)
    {
        // Check for Authorization header
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => ['authorization' => ['Please provide an API token.']]], 422);
        }

        $authorizationHeader = $request->header('Authorization');

        // Validate Authorization header format
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => ['authorization' => ['Invalid token format. Token must start with "Bearer ".']]], 422);
        }

        // Extract the token
        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => ['authorization' => ['Token is missing.']]], 422);
        }

        // Verify the token
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => ['authorization' => ['Unauthorized. Invalid API token.']]], 401);
        }

        try {
            $id = $request->id;

            // Find the property type
            $propertyType = PropertyType::findOrFail($id);

            // Validate request data with custom error handling
            try {
                $validatedData = $request->validate([
                    'name' => [
                        'required',
                        'string',
                        'max:255',
                        Rule::unique('property_types', 'name')->ignore($id), // Unique check ignoring current ID
                    ],
                    'display_property_types_order' => [
                        'nullable',
                        'string',
                        'max:255',
                        Rule::unique('property_types', 'display_property_types_order')->ignore($id), // Unique check ignoring current ID
                    ],
                    'image' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:10240', // Image validation (Max 10MB)
                    'property_id' => [
                        'required',
                        Rule::exists('properties', 'id'), // Validate property_id exists in properties table
                    ],
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['error' => $e->errors()], 422);
            }

            // Determine `display_property_types_order` if not provided
            $displayPropertyTypesOrder = $request->input('display_property_types_order');

            if ($displayPropertyTypesOrder === null) {
                $maxOrder = DB::table('property_types')
                    ->selectRaw('MAX(CAST(display_property_types_order AS UNSIGNED)) as max_order')
                    ->value('max_order');

                $displayPropertyTypesOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

                while (DB::table('property_types')->where('display_property_types_order', $displayPropertyTypesOrder)->exists()) {
                    $displayPropertyTypesOrder++;
                }
            }

            // Handle property image upload and store only the relative path
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $filePath = 'uploads/property_types/' . $fileName; // Store only relative path
                $file->move(public_path('uploads/property_types'), $fileName);
            } else {
                $filePath = $propertyType->image; // Keep the existing image if no new image is uploaded
            }

            // Update the property type
            $propertyType->update([
                'name' => $validatedData['name'],
                'display_property_types_order' => $displayPropertyTypesOrder,
                'image' => $filePath, // Store only relative path
                'property_id' => $validatedData['property_id'],
            ]);

            // Retrieve the updated record only
            $updatedPropertyType = PropertyType::select(
                'id',
                'property_id',
                'name',
                'display_property_types_order',
                'image',
                'created_at',
                'updated_at'
            )->where('id', $id)->first();

            return response()->json([
                'message' => 'Property type data updated successfully.',
                'data' => $updatedPropertyType, // Returns only the updated record
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => ['property_type' => ['Property type not found.']]], 404);
        } catch (\Throwable $th) {
            return response()->json(['error' => ['server' => ['An unexpected error occurred: ' . $th->getMessage()]]], 500);
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
            $property = PropertyType::findOrFail($id); // Find the property by ID

            $filePath = public_path($property->image);

            // Delete the file if it exists
            if (File::exists($filePath)) {
                File::delete($filePath);
            }

            $property->delete(); // Delete the property

            return response()->json(['message' => 'Property deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function getPropertyIds()
    {
        try {
            // Retrieve all property IDs
            $propertyIds = Property::pluck('id', 'name');

            // Return property IDs along with the form as JSON response
            return response()->json(['property_ids' => $propertyIds]);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function storePropertyType(Request $request)
    {

        if ($request->header('api-token') == '') {
            return response()->json(['error' => 'Please enter api token first.'], 422);
        }

        $requestToken = $request->header('api-token');

        $expectedToken = config('constants.API_TOKEN');

        if ($requestToken !== $expectedToken) {
            return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
        }

        try {
            // Validate the incoming request data
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'image' => 'nullable|string|max:255',
                'display_property_types_order' => 'required|string|max:255',
                'property_id' => 'required|exists:properties,id'
            ]);

            // Create the property type
            $propertyType = PropertyType::create([
                'name' => $validatedData['name'],
                'image' => $validatedData['image'],
                'display_property_types_order' => $validatedData['display_property_types_order'],
                'property_id' => $validatedData['property_id']
            ]);

            // Return success response
            return response()->json(['message' => 'Property type created successfully', 'data' => $propertyType], 201);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
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
            // Find the builder by ID
            $delete_ids = explode(',', $request->id);

            foreach ($delete_ids as $row) {
                $property = PropertyType::findOrFail($row);

                $filePath = public_path($property->image);

                // Delete the file if it exists
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
                // Delete the builder record
                $property->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Property type bulk deleted successfully',

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
    //         $purposes = PropertyType::where('name', 'LIKE', '%' . $searchTerm . '%') // Match anywhere in the name
    //                            ->orderBy('name') // Sort alphabetically by name
    //                            ->get();

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
    //         $purposes = PropertyType::orderBy('name')->get(); // Return all purposes sorted alphabetically
    //     }

    //     // Add 'propertyCount' to each purpose
    //     $purposesWithCount = $purposes->map(function ($purpose) {
    //         $assignPropertyCount = Propertylist::where('property_type_id', $purpose->id)->count();  // Get the property count for each purpose
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

        // Verify API token
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Validate search input
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Retrieve the 'name' query parameter
        $searchTerm = $validatedData['name'] ?? '';  // Default to empty string if 'name' is not provided

        if (!empty($searchTerm)) {
            // If search term is provided, filter purposes based on the search term
            $purposes = PropertyType::where('name', 'LIKE', '%' . $searchTerm . '%') // Match anywhere in the name
                ->orderBy('name') // Sort alphabetically by name
                ->get();

            // Sort to ensure that names starting with the search term come first
            $purposes = $purposes->sortBy(function ($purpose) use ($searchTerm) {
                // Priority: If the name starts with the search term, give it a higher priority (0)
                if (strtolower(substr($purpose->name, 0, strlen($searchTerm))) === strtolower($searchTerm)) {
                    return 0; // Highest priority
                }
                return 1; // Default priority for others
            });
        } else {
            // If no search term is provided, return all purposes
            $purposes = PropertyType::orderBy('name')->get(); // Return all purposes sorted alphabetically
        }

        // Add 'propertyCount' and 'property_name' to each purpose
        $purposesWithCount = $purposes->map(function ($purpose) {
            // Get the property count for each purpose
            $assignPropertyCount = PropertyList::where('property_type_id', $purpose->id)->count();
            $purpose->propertyCount = $assignPropertyCount;

            // Fetch 'property_name' from the related Property table
            $propertyName = $purpose->property ? $purpose->property->name : null;
            $purpose->property_name = $propertyName;

            // Remove the full property object (don't include the nested 'property' data)
            unset($purpose->property);

            return $purpose;
        });

        // Ensure the response is in the correct array format
        return response()->json($purposesWithCount->values()->toArray());
    }

}
