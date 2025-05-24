<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Amenity; // Import the Amenity model
use App\Models\AmenitiesCategory; // Import the AmenitiesCategory model
use Illuminate\Support\Facades\DB; // Import the DB facade
use Illuminate\Database\Eloquent\ModelNotFoundException;
class Amenitycontroller extends Controller
{

public function store(Request $request)
{
    try {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:amenities',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Max file size of 2MB
            'amenities_categories_id' => 'required|exists:amenities_categories,id', // Validate existence of category
        ]);

        // Handle file upload
        $image = $request->file('image');
        $imageName = time() . '_' . $image->getClientOriginalName(); // Unique filename

        // Store the image in the storage folder
        $imagePath = $image->storeAs('images/amenitiescategories', $imageName);

        // Create a new amenity record
        $amenity = Amenity::create([
            'name' => $validatedData['name'],
            'image' => $imagePath, // Save the image path in the database
            'amenities_categories_id' => $validatedData['amenities_categories_id'], // Assign category ID
        ]);

        // Retrieve the category name
        $categoryName = AmenitiesCategory::findOrFail($validatedData['amenities_categories_id'])->name;

        // Return the newly created amenity attributes as JSON response
        return response()->json([
            'id' => $amenity->id,
            'amenities_category_name' => $categoryName,
            'name' => $amenity->name,
            'slug' => $amenity->slug,
            'image' => $amenity->image,
            
            'created_at' => $amenity->created_at,
            'updated_at' => $amenity->updated_at,
        ], 201);
    } catch (\Throwable $th) {
        // Handle any exceptions and return an error response
        return response()->json(['error' => $th->getMessage()], 500);
    }
}


public function index()
{
    try {
        // Fetch all amenities from the database along with their corresponding category names
        $data = Amenity::with('category')->get();

        // Transform the data to include category names instead of category IDs
        $transformedData = $data->map(function ($amenity) {
            return [
                'id' => $amenity->id,
                'amenities_category_name' => optional($amenity->category)->name, // Use optional() to handle null category
                'name' => $amenity->name,
                'slug' => $amenity->slug,
                'image' => $amenity->image,
                
                'created_at' => $amenity->created_at,
                'updated_at' => $amenity->updated_at,
            ];
        });

        // Return the transformed data as JSON response
        return response()->json($transformedData);
    } catch (\Throwable $th) {
        // Handle any exceptions and return an error response
        return response()->json(['error' => $th->getMessage()], 500);
    }
}





    public function destroy(Request $request)
{
    try {
        $id = $request->id;
        $amenity = Amenity::findOrFail($id); // Find the amenity by ID

        $amenity->delete(); // Delete the amenity

        return response()->json(['message' => 'Amenity deleted successfully']);
    } catch (ModelNotFoundException $ex) {
        return response()->json(['error' => 'No amenity found'], 404);
    } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage()], 500);
    }
}
    
   public function update(Request $request)
{
    try {
        // Validate the request data
        $validatedData = $request->validate([
            'id' => 'required',
            'name' => 'required|string|max:255',
            'image' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Allow image to be nullable
            'amenities_categories_id' => 'required|exists:amenities_categories,id',
        ]);

        // Find the Amenity by id
        $amenity = Amenity::findOrFail($validatedData['id']);

        // Update the amenity attributes
        $amenity->name = $validatedData['name'];
        $amenity->amenities_categories_id = $validatedData['amenities_categories_id'];

        // Handle file upload if image is provided
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName(); // Unique filename
            $imagePath = $image->storeAs('images/amenitiescategories', $imageName);
            $amenity->image = $imagePath;
        }

        // Save the changes
        $amenity->save();

        // Load the amenities category relation
        $amenity->load('category');

        // Return the updated amenity attributes as JSON response
        return response()->json([
            'id' => $amenity->id,
            'amenities_category_name' => $amenity->category->name,
            'name' => $amenity->name,
            'slug' => $amenity->slug,
            'image' => $amenity->image,
            'created_at' => $amenity->created_at,
            'updated_at' => $amenity->updated_at,
        ]);
    } catch (\Throwable $th) {
        // Handle any exceptions and return an error response
        return response()->json(['error' => $th->getMessage()], 500);
    }
}

}

