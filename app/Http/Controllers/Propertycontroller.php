<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Property;
class Propertycontroller extends Controller
{
    public function store(Request $request)
{
    // Validate the request data
    $request->validate([
        'name' => 'required|string|max:255',
        'property_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Max file size of 2MB
    ]);

    // Check if a record with the same name already exists
    $existingProperty = Property::where('name', $request->input('name'))->first();

    if ($existingProperty) {
        // If a record with the same name exists, return a response indicating that it's a duplicate
        return response()->json(['message' => 'Property with the same name already exists.'], 409);
    }

    // Handle file upload
    $iconPath = null;
    if ($request->hasFile('property_image')) {
        $icon = $request->file('property_image');
        $extension = $icon->getClientOriginalExtension(); // Get the original client extension
        $iconName = time() . '_' . uniqid() . '.' . $extension; // Unique filename with current timestamp
        $iconPath = $icon->storeAs('images/properties', $iconName); // Store the image with the new filename
    }

    // Create a new property record
    $data = Property::create([
        'name' => $request->input('name'),
        'property_image' => $iconPath, // Save the image path in the database
    ]);

    $newData = [
        "id" => $data["id"],
        "name" => $data["name"],
        "slug" => $data["slug"],
        "property_image" => $data["property_image"],
        "updated_at" => $data["updated_at"],
        "created_at" => $data["created_at"]
    ];

    // Return the newly created property as JSON response
    return response()->json($newData, 201);
}


    public function index()
    {
        $purposes = Property::all();
        return response()->json($purposes);
    }

    public function update(Request $request)
{
    $id = $request->id;

    $request->validate([
        'name' => 'required|string|max:255',
        'property_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Allow null or valid image file
    ]);

    $purpose = Property::findOrFail($id);

    // Handle file upload if a new image is provided
    if ($request->hasFile('property_image')) {
        $icon = $request->file('property_image');
        $extension = $icon->getClientOriginalExtension(); // Get the original client extension
        $iconName = time() .$extension; // Unique filename with current timestamp
        $iconPath = $icon->storeAs('images/properties', $iconName); // Store the image with the new filename
        $purpose->property_image = $iconPath; // Update the icon path
    }

    $purpose->update($request->only(['name']));

    return response()->json($purpose, 200);
}


public function destroy(Request $request, Property $property)
{
    try {
        $id = $request->id;
        $property = Property::findOrFail($id); // Find the property by ID

        $property->delete(); // Delete the property

        return response()->json(['message' => 'Property and its children deleted successfully']);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json(['error' => 'Property not found.'], 404);
    } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage()], 500);
    }
}

    public function getPropertyAndType(Request $request, $id)
    {
        $id = $request->id;
        
        $property = Property::with('propertytype')->find($id);
        if(!$property){
            return response()->json(['message'=>'Property Data not found']);
        }
        return response()->json($property);
    }

}
