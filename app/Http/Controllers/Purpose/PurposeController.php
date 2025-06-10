<?php

namespace App\Http\Controllers\Purpose;

use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;
use App\Models\Purpose;
use App\Models\PropertyList;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Storage;
use Illuminate\Validation\Rule;

use Illuminate\Database\Eloquent\ModelNotFoundException;
class PurposeController extends Controller
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
                'name' => 'required|string|max:255|unique:purposes',
                'purpose_display_order' => 'nullable|integer|unique:purposes',
                'icon' => 'nullable', // Accept base64 encoded string
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return validation error response
            return response()->json(['error' => $e->errors()], 422);
        }

        if ($request->hasFile('icon')) {
            $file = $request->file('icon');
            $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
            $file->move(public_path('uploads/purposes'), $name); // Move file to correct folder

            // Generate the URL in the required format
            $profile_image = 'uploads/purposes/' . $name; // Correct path for public access
        } elseif (isset($request->icon) && !empty($request->icon)) {
            // If the 'icon' input is set and not empty, use the provided icon
            $profile_image = $request->input('icon');
        } else {
            // If no icon is uploaded and 'icon' is not set or is empty, set profile_image to null
            $profile_image = null; // Make sure it's properly set to null
        }
        // Determine the purpose display order
        $purposeDisplayOrder = $validatedData['purpose_display_order'];

        // If the purpose_display_order is null, get the maximum value from the table and add 1
        if ($purposeDisplayOrder === null) {
            // Get the maximum display order value and increment it by 1 using raw SQL
            $maxOrder = DB::table('purposes')
                ->selectRaw('MAX(CAST(purpose_display_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            // If no records exist, set to 1, else increment by 1
            $purposeDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            // Check for duplicate purpose_display_order values and increment until a unique value is found
            while (Purpose::where('purpose_display_order', $purposeDisplayOrder)->exists()) {
                $purposeDisplayOrder++;
            }
        }
        // Create a new purpose record
        $purpose = Purpose::create([
            'name' => $validatedData['name'],
            'purpose_display_order' => $purposeDisplayOrder,
            'icon' => $profile_image, // Save the image path in the database
        ]);

        // Return the newly created purpose as JSON response
        return response()->json([
            'message' => 'Purpose created successfully',
            'id' => $purpose->id,
            'purpose_display_order' => $purpose->purpose_display_order,
            'name' => $purpose->name,
            'slug' => $purpose->slug, // You might need to adjust this based on how you generate the slug
            'icon' => $purpose->icon,
            'created_at' => $purpose->created_at,
            'updated_at' => $purpose->updated_at
        ], 201);
    }

    public function getdatabyId(Request $request)
    {
        // Retrieve the purpose based on the id
        $id = $request->id;
        $purpose = Purpose::find($id);

        // Check if the purpose exists
        if (!$purpose) {
            return response()->json(['error' => 'Purpose not found'], 404);
        }

        // Calculate the property count for the purpose
        $propertyCount = PropertyList::where('purpose_id', $purpose->id)->count();

        // Prepare the purpose data
        $purposeData = [
            'id' => $purpose->id,
            'purpose_display_order' => $purpose->purpose_display_order,
            'name' => $purpose->name,
            'slug' => $purpose->slug,
            'icon' => url($purpose->icon),
            'created_at' => $purpose->created_at,
            'updated_at' => $purpose->updated_at,
            'propertyCount' => $propertyCount,  // Add property count here
        ];

        // Unset the icon key if its value is null or the string "null"
        if (empty($purposeData['icon']) || $purpose->icon === "null") {
            unset($purposeData['icon']);
        }

        // Return the purpose data with property count
        return response()->json($purposeData, 200);
    }



    public function update(Request $request)
    {
        // Check for Authorization header
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        $authorizationHeader = $request->header('Authorization');
        if (strpos($authorizationHeader, 'Bearer ') !== 0) {
            return response()->json(['error' => 'Invalid token format.'], 422);
        }

        // Extract the token
        $requestToken = substr($authorizationHeader, 7);
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $id = $request->id;

        // Validate the incoming request data
        try {
            $validatedData = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('purposes')->ignore($id),
                ],
                'purpose_display_order' => [
                    'nullable',
                    'integer',
                    Rule::unique('purposes')->ignore($id),
                ],
                'icon' => 'nullable', // Ensure the icon is an image and limit its size to 10 MB
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        // Retrieve the Purpose instance
        try {
            $purpose = Purpose::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Purpose not found.'], 404);
        }

        // Retrieve the Purpose instance
        try {
            $purpose = Purpose::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Purpose not found.'], 404);
        }

        $profile_image = null; // Default to null
// Determine the purpose display order
        $purposeDisplayOrder = $validatedData['purpose_display_order'];

        // If the purpose_display_order is null, get the maximum value from the table and add 1
        if ($purposeDisplayOrder === null) {
            // Get the maximum display order value and increment it by 1 using raw SQL
            $maxOrder = DB::table('purposes')
                ->selectRaw('MAX(CAST(purpose_display_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            // If no records exist, set to 1, else increment by 1
            $purposeDisplayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            // Check for duplicate purpose_display_order values and increment until a unique value is found
            while (Purpose::where('purpose_display_order', $purposeDisplayOrder)->exists()) {
                $purposeDisplayOrder++;
            }
        }
        // Check if a file is uploaded for the icon
        if ($request->hasFile('icon')) {
            $file = $request->file('icon');
            $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
            $file->move(public_path('uploads/purposes'), $name); // Move file to correct folder

            // Generate the URL in the required format
            $profile_image = 'uploads/purposes/' . $name; // Correct path for public access
        } elseif (isset($request->icon) && !empty($request->icon)) {
            // If the 'icon' input is set and not empty, use the provided icon
            $profile_image = $request->input('icon');
        } else {
            // If no icon is uploaded and 'icon' is not set or is empty, set profile_image to null
            $profile_image = null; // Make sure it's properly set to null
        }
        // Update other fields
        $purpose->name = $request->name;
        $purpose->purpose_display_order = $purposeDisplayOrder;
        $purpose->icon = $profile_image;
        $purpose->save();

        return response()->json([
            'message' => 'Purpose updated successfully',
            'purpose' => $purpose
        ], 200);
    }



    public function index()
    {
        $purposes = Purpose::all()->toArray();

        $formattedPurposes = [];
        foreach ($purposes as $purpose) {
            $assignPropertyCount = PropertyList::where('purpose_id', $purpose['id'])->count();
            $formattedPurposes[] = [
                'id' => $purpose['id'],
                'purpose_display_order' => $purpose['purpose_display_order'],
                'name' => $purpose['name'],
                'slug' => $purpose['slug'],
                'icon' => url($purpose['icon']),
                'created_at' => $purpose['created_at'],
                'updated_at' => $purpose['updated_at'],
                'propertyCount' => $assignPropertyCount,
            ];
        }

        return response()->json($formattedPurposes);
    }


    public function destroy(Request $request)
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

        $userId = null;

        $userData = User::where('api_token', $requestToken)->first();

        $userId = $userData->id;

        // Validate that the user exists in the database
        if ($userId && !User::where('id', $userId)->exists()) {
            return response()->json(['error' => 'User not found'], 404);
        }
        try {
            // Find the builder by ID
            $id = $request->id;
            $purpose = Purpose::findOrFail($id);

            // $filePath = public_path('uploads/purposes/' . $purpose->icon);

            // If you stored the full relative path, use:
            $filePath = public_path($purpose->icon);

            // Delete the file if it exists
            if (File::exists($filePath)) {
                File::delete($filePath);
            }

            // Delete the builder record
            $purpose->delete();

            // Return a success response
            return response()->json([
                'message' => 'purpose deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'purpose not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong'], 500);
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
                $purpose = Purpose::findOrFail($row);

                $filePath = public_path($purpose->icon);

                // Delete the file if it exists
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
                // Delete the builder record
                $purpose->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Purpose bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'purpose not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }
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

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Retrieve the 'name' query parameter
        $searchTerm = $validatedData['name'] ?? '';  // Default to empty string if 'name' is not provided

        if (!empty($searchTerm)) {
            // If search term is provided, filter purposes based on the search term
            $purposes = Purpose::where('name', 'LIKE', '%' . $searchTerm . '%') // Match anywhere in the name
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
            $purposes = Purpose::orderBy('name')->get(); // Return all purposes sorted alphabetically
        }

        // Add 'propertyCount' to each purpose
        $purposesWithCount = $purposes->map(function ($purpose) {
            $assignPropertyCount = PropertyList::where('purpose_id', $purpose->id)->count();  // Get the property count for each purpose
            $purpose->propertyCount = $assignPropertyCount;  // Add the property count to the purpose
            return $purpose;
        });

        // Ensure the response is in the correct array format
        return response()->json($purposesWithCount->values()->toArray());
    }




}
