<?php

namespace App\Http\Controllers\Amenity;

use DB;
use Illuminate\Http\Request;
use App\Models\AmenitiesCategory;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Storage;
use App\Models\Media;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class AmenitycategoriesController extends Controller
{
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
            // Validate the request data
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:amenities_categories',
                'display_amenities_categories_order' => 'nullable|integer|unique:amenities_categories',
                'icon_id' => 'nullable|exists:media,id', // Check if the provided ID exists in the media table
                'icon_name' => 'nullable|string|max:255',
                'icon_css' => 'nullable|string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return validation error response
            return response()->json(['error' => $e->errors()], 422);
        }
        // Initialize variables to hold media information
        $mediaId = null;
        $mediaName = null;
        $mediaCss = null;

        // Check which media field is provided and retrieve media information accordingly
        if (isset($validatedData['icon_id'])) {
            $mediaId = $validatedData['icon_id'];
            $media = Media::findOrFail($mediaId); // Retrieve media based on provided ID
            $mediaName = $media->icon_name;
            $mediaCss = $media->icon_css_id;
        } elseif (isset($validatedData['icon_name'])) {
            $mediaName = $validatedData['icon_name'];
            $media = Media::where('icon_name', $mediaName)->firstOrFail(); // Retrieve media based on icon name
            $mediaId = $media->id;
            $mediaCss = $media->icon_css_id;
        } elseif (isset($validatedData['icon_css'])) {
            $mediaCss = $validatedData['icon_css'];
            $media = Media::where('icon_css_id', $mediaCss)->firstOrFail(); // Retrieve media based on icon css
            $mediaId = $media->id;
            $mediaName = $media->icon_name;
        }
        // Determine the purpose display order
        $amenitiescategoriesDisplayOrder = $validatedData['display_amenities_categories_order'];

        // If the purpose_display_order is null, get the maximum value from the table and add 1
        if ($amenitiescategoriesDisplayOrder === null) {
            // Get the maximum display order value and increment it by 1 using raw SQL
            $maxOrder = DB::table('amenities_categories')
                ->selectRaw('MAX(CAST(display_amenities_categories_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            // If no records exist, set to 1, else increment by 1
            $amenitiescategoriesDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            // Check for duplicate purpose_display_order values and increment until a unique value is found
            while (AmenitiesCategory::where('display_amenities_categories_order', $amenitiescategoriesDisplayOrder)->exists()) {
                $amenitiescategoriesDisplayOrder++;
            }
        }
        // Create a new amenities category record
        $amenitiesCategory = AmenitiesCategory::create([
            'name' => $validatedData['name'],
            'display_amenities_categories_order' => $amenitiescategoriesDisplayOrder,
            'icon_id' => $mediaId,
            'icon_name' => $mediaName,
            'icon_css' => $mediaCss,

        ]);

        // Return the newly created amenities category as JSON response
        return response()->json([
            'id' => $amenitiesCategory->id,
            'name' => $amenitiesCategory->name,
            'slug' => $amenitiesCategory->slug,
            'category_display_order' => $amenitiesCategory->display_amenities_categories_order,
            'icon_id' => $amenitiesCategory->icon_id,
            'icon_name' => $amenitiesCategory->icon_name,
            'icon_css' => $amenitiesCategory->icon_css,
            // 'image' => $amenitiesCategory->image,
            'created_at' => $amenitiesCategory->created_at,
            'updated_at' => $amenitiesCategory->updated_at
        ], 201);
    }

    public function index()
    {
        try {
            // Fetch all amenities categories with their related media from the database
            $amenitiesCategories = AmenitiesCategory::with('media')->get();

            // Initialize an empty array to hold formatted amenities categories
            $formattedAmenitiesCategories = [];

            // Loop through each amenities category and format the data
            foreach ($amenitiesCategories as $amenitiesCategory) {
                $formattedMedia = null;

                // Check if there is related media
                if ($amenitiesCategory->media) {
                    // Format the media data
                    $formattedMedia = [
                        'id' => $amenitiesCategory->media->id,
                        'icon_name' => $amenitiesCategory->media->icon_name,
                        'icon_css_id' => $amenitiesCategory->media->icon_css_id,
                        'created_at' => $amenitiesCategory->media->created_at,
                        'updated_at' => $amenitiesCategory->media->updated_at,
                        // Add other media attributes here as needed
                    ];
                }

                // Format the amenities category data
                $formattedAmenitiesCategories[] = [
                    'id' => $amenitiesCategory->id,
                    'display_amenities_categories_order' => $amenitiesCategory->display_amenities_categories_order,
                    'name' => $amenitiesCategory->name,
                    'slug' => $amenitiesCategory->slug,
                    // 'media' => $formattedMedia,
                    'icon_id' => $amenitiesCategory->icon_id,
                    'icon_name' => $amenitiesCategory->icon_name,
                    'icon_css' => $amenitiesCategory->icon_css,
                    // 'media' => $formattedMedia,
                    // 'media' => $formattedMedia,
                    'created_at' => $amenitiesCategory->created_at,
                    'updated_at' => $amenitiesCategory->updated_at,
                    // Add other attributes here as needed
                ];
            }

            // Return the formatted amenities categories as JSON response
            return response()->json($formattedAmenitiesCategories);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function getDataById(Request $request)
    {
        try {
            // Fetch the amenity category with the specified ID along with its related media
            $id = $request->id;
            $amenitiesCategory = AmenitiesCategory::with('media')->findOrFail($id);

            // Initialize an array to hold formatted data for the amenity category
            $formattedData = [
                'id' => $amenitiesCategory->id,
                'display_amenities_order' => $amenitiesCategory->display_amenities_categories_order,
                'name' => $amenitiesCategory->name,
                'slug' => $amenitiesCategory->slug,
                'icon_id' => $amenitiesCategory->icon_id,
                'icon_name' => $amenitiesCategory->icon_name,
                'icon_css' => $amenitiesCategory->icon_css,
                'created_at' => $amenitiesCategory->created_at,
                'updated_at' => $amenitiesCategory->updated_at,
                // 'media' => null, // Initialize media data as null
            ];

            // Check if there is related media
            if ($amenitiesCategory->media) {
                // Format the media data
                $formattedData['media'] = [
                    'id' => $amenitiesCategory->media->id,
                    'icon_name' => $amenitiesCategory->media->icon_name,
                    'icon_css_id' => $amenitiesCategory->media->icon_css_id,
                    'created_at' => $amenitiesCategory->media->created_at,
                    'updated_at' => $amenitiesCategory->media->updated_at,
                    // Add other media attributes here as needed
                ];
            }

            // Return the formatted data for the amenity category as JSON response
            return response()->json($formattedData);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function update(Request $request)
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

            // Check if the amenities category exists before proceeding
            $amenitiesCategory = AmenitiesCategory::find($id);

            if (!$amenitiesCategory) {
                return response()->json(['error' => 'Amenities category not found.'], 404);
            }

            try {
                $validatedData = $request->validate([
                    'name' => [
                        'required',
                        'string',
                        'max:255',
                        Rule::unique('amenities_categories')->ignore($id),
                    ],
                    'display_amenities_categories_order' => [
                        'nullable',
                        'integer',
                        Rule::unique('amenities_categories')->ignore($id),
                    ],
                    'icon_id' => 'nullable|exists:media,id', // Make sure the ID exists
                    'icon_name' => 'nullable|exists:media,icon_name', // Make sure the icon_name exists
                    'icon_css' => 'nullable|exists:media,icon_css_id', // Make sure the icon_css exists
                ]);

            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['error' => $e->errors()], 422);
            }

            // Initialize variables to hold media information
            $mediaId = null;
            $mediaName = null;
            $mediaCss = null;

            // Check which media field is provided and retrieve media information accordingly
            if (isset($validatedData['icon_id'])) {
                $mediaId = $validatedData['icon_id'];
                $media = Media::findOrFail($mediaId); // Retrieve media based on provided ID
                $mediaName = $media->icon_name;
                $mediaCss = $media->icon_css_id;
            } elseif (isset($validatedData['icon_name'])) {
                $mediaName = $validatedData['icon_name'];
                $media = Media::where('icon_name', $mediaName)->firstOrFail(); // Retrieve media based on icon name
                $mediaId = $media->id;
                $mediaCss = $media->icon_css_id;
            } elseif (isset($validatedData['icon_css'])) {
                $mediaCss = $validatedData['icon_css'];
                $media = Media::where('icon_css_id', $mediaCss)->firstOrFail(); // Retrieve media based on icon css
                $mediaId = $media->id;
                $mediaName = $media->icon_name;
            }

            // Determine the purpose display order
            $amenitiescategoriesDisplayOrder = $validatedData['display_amenities_categories_order'];

            // If the purpose_display_order is null, get the maximum value from the table and add 1
            if ($amenitiescategoriesDisplayOrder === null) {
                // Get the maximum display order value and increment it by 1 using raw SQL
                $maxOrder = DB::table('amenities_categories')
                    ->selectRaw('MAX(CAST(display_amenities_categories_order AS UNSIGNED)) as max_order')
                    ->value('max_order');

                // If no records exist, set to 1, else increment by 1
                $amenitiescategoriesDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

                // Check for duplicate purpose_display_order values and increment until a unique value is found
                while (AmenitiesCategory::where('display_amenities_categories_order', $amenitiescategoriesDisplayOrder)->exists()) {
                    $amenitiescategoriesDisplayOrder++;
                }
            }

            // Update the amenities category record
            $amenitiesCategory->update([
                'name' => $validatedData['name'],
                'display_amenities_categories_order' => $amenitiescategoriesDisplayOrder,
                'icon_id' => $mediaId,
                'icon_name' => $mediaName,
                'icon_css' => $mediaCss,
            ]);

            // Return the updated amenities category as JSON response
            return response()->json([
                'id' => $amenitiesCategory->id,
                'icon_id' => $amenitiesCategory->icon_id,
                'icon_name' => $amenitiesCategory->icon_name,
                'icon_css' => $amenitiesCategory->icon_css,
                'name' => $amenitiesCategory->name,
                'slug' => $amenitiesCategory->slug,
                'category_display_order' => $amenitiesCategory->display_amenities_categories_order,
                'created_at' => $amenitiesCategory->created_at,
                'updated_at' => $amenitiesCategory->updated_at
            ], 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
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
        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        try {
            $id = $request->id;
            $amenityCategory = AmenitiesCategory::findOrFail($id); // Find the amenity category by ID

            $amenityCategory->delete(); // Delete the amenity category

            return response()->json(['message' => 'Amenity category deleted successfully']);
        } catch (ModelNotFoundException $e) {
            // Handle the case where the ID is not found
            return response()->json(['error' => 'Amenity category not found.'], 404);
        } catch (\Throwable $th) {
            // Handle any other exceptions and return an error response
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
                $purpose = AmenitiesCategory::findOrFail($row);
                // Delete the builder record
                $purpose->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Amenities category bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'purpose not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }


    // Search by name

    public function searchByName(Request $request)
    {
        try {
            $searchName = $request->input('search');


            // Build query
            $query = AmenitiesCategory::query();

            if (!empty($searchName)) {
                $query->where('name', 'like', '%' . $searchName . '%');
            }

            // Execute query with pagination
            $categories = $query->paginate(10);

            // Return formatted response
            return response()->json([
                'status' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Server error',
                'error' => $e->getMessage()
            ], 500);
        }

    }

}
