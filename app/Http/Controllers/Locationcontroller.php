<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Location;
class Locationcontroller extends Controller
{
    public function store(Request $request)
{
    try {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:locations',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Max file size of 2MB
        ]);

        // Handle file upload
        $image = $request->file('image');
        $imageName = time() . '_' . $image->getClientOriginalName(); // Unique filename

        // Store the image in the storage folder
        $imagePath = $image->storeAs('images/locations', $imageName);

        // Create a new location record
        $location = Location::create([
            'name' => $validatedData['name'],
            'image' => $imagePath, // Save the image path in the database
        ]);

        // Return the newly created location as JSON response
        return response()->json([
            'id'=>$location['id'],
            'name'=>$location['name'],
            'slug'=>$location['slug'],
            'image'=>$location['image'],
            'created_at'=>$location['created_at'],
            'updated_at'=>$location['updated_at'],
        ], 201);
    } catch (\Throwable $th) {
        // Handle any exceptions and return an error response
        return response()->json(['error' => $th->getMessage()], 500);
    }
}


public function update(Request $request)
{
    try {
        // Find the property type by ID
        $id=$request->id;
        $propertyType = Location::findOrFail($id);

        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Allow null or valid image file
        ]);

        // If image is provided, handle file upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName(); // Unique filename
            $imagePath = $image->storeAs('images/location', $imageName);
            $validatedData['image'] = $imagePath;
        }

        // Update the property type with the validated data
        $propertyType->update($validatedData);

        // Return the updated property type as JSON response
        return response()->json($propertyType);
    } catch (\Throwable $th) {
        // Handle any exceptions and return an error response
        return response()->json(['error' => $th->getMessage()], 500);
    }
}




public function index()
    {
       
        try {
            // Fetch all locations from the database
            $locations = Location::all();

            // Return the locations as JSON response
            return response()->json($locations);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function destroy(Request $request)
    {
        try {
            $id=$request->id;
            $property = Location::findOrFail($id); // Find the property by ID

            $property->delete(); // Delete the property

            return response()->json(['message' => 'Location deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


}
