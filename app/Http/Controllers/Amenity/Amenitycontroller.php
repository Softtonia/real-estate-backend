<?php

namespace App\Http\Controllers\Amenity;

use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;
use App\Models\Amenity;
use App\Models\Media;
use App\Models\AmenitiesCategory;
use Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class AmenityController extends Controller
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
                'name' => 'required|unique:amenities,name',
                'media_name' => 'nullable|string|max:255',
                'media_css' => 'nullable|string|max:255',
                'media_id' => 'nullable|exists:media,id',
                'amenities_categories_id' => 'required|exists:amenities_categories,id',
                'display_amenities_order' => 'nullable|unique:amenities,display_amenities_order',
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
        if (isset($validatedData['media_id'])) {
            $mediaId = $validatedData['media_id'];
            $media = Media::findOrFail($mediaId);
            $mediaName = $media->icon_name;
            $mediaCss = $media->icon_css_id;
        } elseif (isset($validatedData['media_name'])) {
            $mediaName = $validatedData['media_name'];
            $media = Media::where('icon_name', $mediaName)->firstOrFail();
            $mediaId = $media->id;
            $mediaCss = $media->icon_css_id;
        } elseif (isset($validatedData['media_css'])) {
            $mediaCss = $validatedData['media_css'];
            $media = Media::where('css', $mediaCss)->firstOrFail();
            $mediaId = $media->id;
            $mediaName = $media->icon_name;
        }
        // Determine the purpose display order
        $amenitiesDisplayOrder = $validatedData['display_amenities_order'];

        // If the amenities_display_order is null, get the maximum value from the table and add 1
        if ($amenitiesDisplayOrder === null) {
            // Get the maximum display order value and increment it by 1 using raw SQL
            $maxOrder = DB::table('amenities')
                ->selectRaw('MAX(CAST(display_amenities_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            // If no records exist, set to 1, else increment by 1
            $amenitiesDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            // Check for duplicate display_amenities_order values and increment until a unique value is found
            while (Amenity::where('display_amenities_order', $amenitiesDisplayOrder)->exists()) {
                $amenitiesDisplayOrder++;
            }
        }

        // Create a new amenity record
        $amenity = Amenity::create([
            'name' => $validatedData['name'],
            'display_amenities_order' => $amenitiesDisplayOrder,
            'media_id' => $mediaId,
            'media_name' => $mediaName,
            'media_css' => $mediaCss,
            'amenities_categories_id' => $validatedData['amenities_categories_id'],
        ]);

        // Retrieve the category name
        $categoryName = AmenitiesCategory::findOrFail($validatedData['amenities_categories_id'])->name;

        // Return the newly created amenity attributes along with media information as JSON response
        return response()->json([
            'id' => $amenity->id,
            'media_id' => $amenity->media_id,
            'amenities_category_name' => $categoryName,
            'name' => $amenity->name,
            'display_amenities_order' => $amenity->display_amenities_order,
            'slug' => $amenity->slug,
            'media_name' => $amenity->media_name,
            'media_css' => $amenity->media_css,
            'created_at' => $amenity->created_at,
            'updated_at' => $amenity->updated_at,
        ], 201);

    }





    public function getdatabyId(Request $request)
    {
        try {
            // Fetch the amenity category with the specified ID along with its related media and category
            $id = $request->id;
            $amenitiesCategory = Amenity::with('media', 'category')->findOrFail($id);



            // Initialize an array to hold formatted data for the amenity category
            $formattedData = [
                'id' => $amenitiesCategory->id,
                'display_amenities_order' => $amenitiesCategory->display_amenities_order,
                'name' => $amenitiesCategory->name,
                'slug' => $amenitiesCategory->slug,
                'created_at' => $amenitiesCategory->created_at,
                'updated_at' => $amenitiesCategory->updated_at,
                'media' => null, // Initialize media data as null
                'category' => null, // Initialize category data as null
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

            // Check if there is related category
            if ($amenitiesCategory->category) {
                // Format the category data
                $formattedData['category'] = [
                    'id' => $amenitiesCategory->category->id,
                    'name' => $amenitiesCategory->category->name,
                    'icon_name' => $amenitiesCategory->category->icon_name,
                    'icon_css' => $amenitiesCategory->category->icon_css,
                    'slug' => $amenitiesCategory->category->slug,
                    'display_amenities_categories_order' => $amenitiesCategory->category->display_amenities_categories_order,
                    // Add other category attributes here as needed
                ];
            }

            // Return the formatted data for the amenity category as JSON response
            return response()->json($formattedData);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
        // Return the purpose data
        return response()->json(['amenity' => $purpose], 200);
    }


    public function index()
    {
        try {
            // Fetch all amenities with their related media and category from the database
            $amenities = Amenity::with('media', 'category')->get();


            // Initialize an empty array to hold formatted amenities
            $formattedAmenities = [];

            // Loop through each amenity and format the data
            foreach ($amenities as $amenity) {
                $formattedAmenities[] = [
                    'id' => $amenity->id,
                    'display_amenities_order' => $amenity->display_amenities_order,
                    'name' => $amenity->name,
                    'slug' => $amenity->slug,
                    'media' => $amenity->media ? [
                        'id' => $amenity->media->id,
                        'icon_name' => $amenity->media->icon_name,
                        'icon_css_id' => $amenity->media->icon_css_id,
                        'created_at' => $amenity->media->created_at,
                        'updated_at' => $amenity->media->updated_at,
                        // 'url' => $amenity->media->url,
                        // Add other media attributes here as needed
                    ] : null,
                    'category' => $amenity->category ? [
                        'id' => $amenity->category->id,
                        'name' => $amenity->category->name,
                        'slug' => $amenity->category->slug,
                        'display_amenities_categories_order' => $amenity->category->display_amenities_categories_order,
                        'image' => $amenity->category->image,
                        'created_at' => $amenity->category->created_at,
                        'updated_at' => $amenity->category->updated_at,
                        // Add other category attributes here as needed
                    ] : null,
                    'created_at' => $amenity->created_at,
                    'updated_at' => $amenity->updated_at,
                    // Add other attributes here as needed
                ];
            }

            // Return the formatted amenities as JSON response
            return response()->json($formattedAmenities);
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

        try {
            $id = $request->id;

            // Check if ID is provided
            if (!$id) {
                throw new \InvalidArgumentException("Amenity ID is missing.");
            }

            $amenity = Amenity::findOrFail($id); // Find the amenity by ID

            $amenity->delete(); // Delete the amenity

            return response()->json(['message' => 'Amenity deleted successfully']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Amenity not found.'], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


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

        $id = intval($request->id);
        if (!$id) {
            return response()->json(['error' => 'Invalid Amenity ID.'], 400);
        }

        $amenity = Amenity::find($id);
        if (!$amenity) {
            return response()->json(['error' => 'Amenity not found.'], 404);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'required|unique:amenities,name,' . $id,
                'media_name' => 'nullable|string|max:255',
                'media_css' => 'nullable|string|max:255',
                'media_id' => 'nullable|exists:media,id',
                'amenities_categories_id' => 'exists:amenities_categories,id',
                'display_amenities_order' => 'nullable|unique:amenities,display_amenities_order,' . $id,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        if (isset($validatedData['name'])) {
            $amenity->name = $validatedData['name'];
        }

        if (isset($validatedData['display_amenities_order'])) {
            $amenity->display_amenities_order = $validatedData['display_amenities_order'];
        }

        if (isset($validatedData['amenities_categories_id'])) {
            $amenity->amenities_categories_id = $validatedData['amenities_categories_id'];
        }

        if (isset($validatedData['media_id'])) {
            $media = Media::find($validatedData['media_id']);
            if ($media) {
                $amenity->media_id = $media->id;
                $amenity->media_name = $media->icon_name;
                $amenity->media_css = $media->icon_css_id;
            }
        }
        // Determine the purpose display order
        $amenitiesDisplayOrder = $validatedData['display_amenities_order'];

        // If the amenities_display_order is null, get the maximum value from the table and add 1
        if ($amenitiesDisplayOrder === null) {
            // Get the maximum display order value and increment it by 1 using raw SQL
            $maxOrder = DB::table('amenities')
                ->selectRaw('MAX(CAST(display_amenities_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            // If no records exist, set to 1, else increment by 1
            $amenitiesDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            // Check for duplicate display_amenities_order values and increment until a unique value is found
            while (Amenity::where('display_amenities_order', $amenitiesDisplayOrder)->exists()) {
                $amenitiesDisplayOrder++;
            }
        }
        $amenity->save();

        $categoryName = AmenitiesCategory::find($amenity->amenities_categories_id)?->name ?? 'Unknown';

        return response()->json([
            'id' => $amenity->id,
            'media_id' => $amenity->media_id,
            'amenities_category_name' => $categoryName,
            'name' => $amenity->name,
            'display_amenities_order' => $amenitiesDisplayOrder,
            'slug' => $amenity->slug,
            'media_name' => $amenity->media_name,
            'media_css' => $amenity->media_css,
            'created_at' => $amenity->created_at,
            'updated_at' => $amenity->updated_at,
        ], 200);

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
                $purpose = Amenity::findOrFail($row);
                // Delete the builder record
                $purpose->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Amenity bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'purpose not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }


}
