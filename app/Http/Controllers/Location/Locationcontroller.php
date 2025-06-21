<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;
use App\Models\Location;
use App\Models\User;
use App\Models\Country;
use App\Models\City;
use App\Models\State;
use App\Models\PropertyList;
use Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Storage;
use Str;
use Illuminate\Validation\Rule;

use Illuminate\Database\Eloquent\ModelNotFoundException;


class LocationController extends Controller
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
                'name' => 'required|string|max:255|unique:locations',
                'image' => 'nullable', // Allow null or valid image file with a maximum size of 2MB
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return validation error response
            return response()->json(['error' => $e->errors()], 422);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
            $file->move(public_path('uploads/locations'), $name); // Move file to correct folder

            // Generate the URL in the required format
            $profile_image = url('uploads/locations/' . $name); // Correct path for public access
        } elseif (isset($request->image) && !empty($request->image)) {
            // If the 'image' input is set and not empty, use the provided image
            $profile_image = $request->input('image');
        } else {
            // If no icon is uploaded and 'icon' is not set or is empty, set profile_image to null
            $profile_image = null; // Make sure it's properly set to null
        }

        // Generate the slug for the location
        $slug = Str::slug($validatedData['name']);

        // Create a new location record
        $location = Location::create([
            'name' => $validatedData['name'],
            'slug' => $slug,
            'image' => $profile_image, // Save the image path in the database
        ]);

        // Return the newly created location as JSON response
        return response()->json([
            'id' => $location->id,
            'name' => $location->name,
            'slug' => $location->slug,
            'image' => $location->image,
            'created_at' => $location->created_at,
            'updated_at' => $location->updated_at
        ], 201);

    }

    public function getdatabyId(Request $request)
    {
        // Retrieve the location based on the id
        $id = $request->input('id');
        $location = Location::find($id);

        // Check if the location exists
        if (!$location) {
            return response()->json(['error' => 'Location not found'], 404);
        }

        // Calculate the property count for the location
        $propertyCount = PropertyList::where('location_id', $location->id)->count();

        // Prepare the location data
        $locationData = [
            'id' => $location->id,
            'name' => $location->name,
            'slug' => $location->slug,
            'image' => $location->image,
            'created_at' => $location->created_at,
            'updated_at' => $location->updated_at,
            'propertyCount' => $propertyCount,  // Add property count here
        ];

        // Unset the image key if its value is null or the string "null"
        if (empty($locationData['image']) || $location->image === "null") {
            unset($locationData['image']);
        }

        // Return the location data
        return response()->json(['location' => $locationData], 200);
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

            // Find the location based on the ID
            $location = Location::findOrFail($id);

            // Validate the request data with custom error handling
            try {
                $validatedData = $request->validate([
                    'name' => [
                        'required',
                        'string',
                        'max:255',
                        Rule::unique('locations')->ignore($id), // Unique check ignoring current ID
                    ],
                    'image' => 'nullable', // Optional image field
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['error' => $e->errors()], 422);
            }

            // Handle the image upload or base64 input
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName()); // Replace spaces with underscores
                $file->move(public_path('uploads/locations'), $name); // Move file to correct folder

                // Generate the URL in the required format
                $profile_image = url('uploads/locations/' . $name); // Correct path for public access
            } elseif (isset($request->image) && !empty($request->image)) {
                // If the 'image' input is set and not empty, use the provided image
                $profile_image = $request->input('image');
            } else {
                // If no image is uploaded and 'image' is not set or is empty, set profile_image to null
                $profile_image = null; // Make sure it's properly set to null
            }

            // Update the location
            $location->update([
                'name' => $validatedData['name'],
                'image' => $profile_image,
            ]);

            // Return success response with updated location data
            return response()->json([
                'message' => 'Location updated successfully.',
                'data' => $location,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => ['location' => ['Location not found.']]], 404);
        } catch (\Throwable $th) {
            return response()->json(['error' => ['server' => ['An unexpected error occurred: ' . $th->getMessage()]]], 500);
        }
    }


    public function index()
    {

        try {
            $locations = Location::get();

            foreach ($locations as $row) {
                $assignPropertyCount = PropertyList::where('location_id', $row->id)->count();
                $row->propertyCount = $assignPropertyCount;
            }

            // Return the locations as JSON response
            return response()->json([
                $locations
            ]);
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
            // Find the location by ID
            $id = $request->id;
            $location = Location::findOrFail($id);

            // Delete the location
            $location->delete();

            return response()->json(['message' => 'Location deleted successfully']);
        } catch (ModelNotFoundException $e) {
            // Handle the case where the ID is not found
            return response()->json(['error' => 'Location not found'], 404);
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
                $purpose = Location::findOrFail($row);
                // Delete the builder record
                $purpose->delete();
            }

            // Return a success response
            return response()->json([
                'message' => 'Location bulk deleted successfully',

            ], 200);
        } catch (ModelNotFoundException $e) {
            // Handle model not found errors
            return response()->json(['error' => 'Location not found'], 404);
        } catch (\Exception $e) {
            // Handle other unexpected errors
            return response()->json(['error' => $e->getMessage() . ' ' . $e->getLine() . ' ' . $e->getFile()], 500);
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
            $purposes = Location::where('name', 'LIKE', '%' . $searchTerm . '%') // Match anywhere in the name
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
            $purposes = Location::orderBy('name')->get(); // Return all purposes sorted alphabetically
        }

        // Add 'propertyCount' to each purpose
        $purposesWithCount = $purposes->map(function ($purpose) {
            $assignPropertyCount = PropertyList::where('location_id', $purpose->id)->count();  // Get the property count for each purpose
            $purpose->propertyCount = $assignPropertyCount;  // Add the property count to the purpose
            return $purpose;
        });

        // Ensure the response is in the correct array format
        return response()->json($purposesWithCount->values()->toArray());
    }

    public function getCountries()
    {
        $countries = Country::all();
        return response()->json($countries, 200);
    }

    // Get states by country ID
    public function getStatesByCountry($countryId)
    {
        // Check if country exists
        $country = Country::find($countryId);

        if (!$country) {
            return response()->json(['error' => 'Country not found.'], 404);
        }

        // Fetch the states associated with the given country
        $states = State::where('country_id', $countryId)->get();

        // Return the states as a JSON response
        return response()->json($states, 200);
    }

    // Get cities by state ID
    public function getCitiesByState($stateId)
    {
        // Find the state by ID
        $state = State::find($stateId);

        if (!$state) {
            return response()->json(['error' => 'State not found.'], 404);
        }

        // Fetch cities associated with the state
        $cities = City::where('state_id', $stateId)->get();

        // Return the cities as a JSON response
        return response()->json($cities, 200);
    }


    // Bulk upload country , state and city

    // public function bulkUploadCSC(Request $request)
    // {
    //     try {
    //         // Check for Authorization header
    //         if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //             return response()->json(['error' => ['authorization' => ['Please provide an API token.']]], 422);
    //         }

    //         $authorizationHeader = $request->header('Authorization');

    //         // Validate Authorization header format
    //         if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //             return response()->json(['error' => ['authorization' => ['Invalid token format. Token must start with "Bearer ".']]], 422);
    //         }

    //         // Extract the token
    //         $requestToken = substr($authorizationHeader, 7);

    //         if (empty($requestToken)) {
    //             return response()->json(['error' => ['authorization' => ['Token is missing.']]], 422);
    //         }

    //         // Verify the token
    //         $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

    //         if (!$tokenExists) {
    //             return response()->json(['error' => ['authorization' => ['Unauthorized. Invalid API token.']]], 401);
    //         }

    //         // Validate file upload
    //         $request->validate([
    //             'file' => 'required|file|mimes:csv,txt',
    //         ]);

    //         $file = $request->file('file');
    //         $path = $file->getRealPath();
    //         $rows = array_map('str_getcsv', file($path));
    //         $header = array_map('strtolower', array_map('trim', $rows[0]));
    //         unset($rows[0]); // Remove header

    //         DB::beginTransaction();

    //         foreach ($rows as $row) {
    //             $data = array_combine($header, $row);

    //             // Insert country
    //             $country = Country::firstOrCreate(['name' => $data['country']]);

    //             // Insert state
    //             $state = State::firstOrCreate([
    //                 'name' => $data['state'],
    //                 'country_id' => $country->id,
    //             ]);

    //             // Insert city
    //             City::firstOrCreate([
    //                 'name' => $data['city'],
    //                 'state_id' => $state->id,
    //             ]);
    //         }

    //         DB::commit();
    //         return response()->json(['message' => 'Data uploaded successfully.']);

    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         return response()->json(['error' => $e->errors()], 422);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
    //     }
    // }

    public function bulkUploadCSC(Request $request)
    {
        try {
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => ['authorization' => ['Please provide an API token.']]], 422);
            }

            $authorizationHeader = $request->header('Authorization');

            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => ['authorization' => ['Invalid token format. Token must start with "Bearer ".']]], 422);
            }

            $requestToken = substr($authorizationHeader, 7);

            if (empty($requestToken)) {
                return response()->json(['error' => ['authorization' => ['Token is missing.']]], 422);
            }

            $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

            if (!$tokenExists) {
                return response()->json(['error' => ['authorization' => ['Unauthorized. Invalid API token.']]], 401);
            }

            $request->validate([
                'file' => 'required|file|mimes:csv,txt',
            ]);

            $file = $request->file('file');
            $path = $file->getRealPath();
            $rows = array_map('str_getcsv', file($path));
            $header = array_map('strtolower', array_map('trim', $rows[0]));
            unset($rows[0]);

            DB::beginTransaction();

            foreach ($rows as $row) {
                $data = array_combine($header, $row);

                // Country check
                $country = Country::where('name', $data['country'])->first();
                if (!$country) {
                    $country = Country::create(['name' => $data['country']]);
                }

                // State check
                $state = State::where('name', $data['state'])->where('country_id', $country->id)->first();
                if (!$state) {
                    $state = State::create([
                        'name' => $data['state'],
                        'country_id' => $country->id,
                    ]);
                }

                // City check
                $city = City::where('name', $data['city'])->where('state_id', $state->id)->first();
                if (!$city) {
                    City::create([
                        'name' => $data['city'],
                        'state_id' => $state->id,
                    ]);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Data uploaded successfully.']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }


    public function locationList()
    {
        $data = DB::table('countries')
            ->join('states', 'states.country_id', '=', 'countries.id')
            ->join('cities', 'cities.state_id', '=', 'states.id')
            ->select(
                'countries.id as country_id',
                'countries.name as country_name',
                'states.id as state_id',
                'states.name as state_name',
                'cities.id as city_id',
                'cities.name as city_name'
            )
            ->orderBy('countries.name')
            ->orderBy('states.name')
            ->orderBy('cities.name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'All location data fetched successfully',
            'data' => $data
        ]);
    }



}
