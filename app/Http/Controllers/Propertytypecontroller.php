<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PropertyType;
use App\Models\Property;
class Propertytypecontroller extends Controller
{
//     public function store(Request $request)
// {
//     try {
//         // Validate the request data
//         $validatedData = $request->validate([
//             'name' => 'required|string|max:255',
//             'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Max file size of 2MB
//             'property_id' => 'required|exists:properties,id', // Ensure property_id exists in properties table
//         ]);

//         // Check if a property type with the same name already exists for the given property
//         $existingPropertyType = PropertyType::where('name', $validatedData['name'])
//                                             ->where('property_id', $validatedData['property_id'])
//                                             ->exists();

//         if ($existingPropertyType) {
//             // If a property type with the same name already exists for the given property, return a response indicating it's a duplicate
//             return response()->json(['message' => 'Property type with the same name already exists for this property.'], 409);
//         }

//         // Handle file upload
//         $image = $request->file('image');
//         $imageName = time() . '_' . $image->getClientOriginalName(); // Unique filename

//         // Store the image in the storage folder
//         $imagePath = $image->storeAs('images/property_types', $imageName);

//         // Create a new property type record
//         $propertyType = PropertyType::create([
//             'name' => $validatedData['name'],
//             'image' => $imagePath, // Save the image path in the database
//             'property_id' => $validatedData['property_id'], // Set the property_id
//         ]);

//         // Fetch the associated property
//         $property = Property::findOrFail($validatedData['property_id']);

//         // Return the newly created property type with associated property name as JSON response
//         return response()->json([
//             'id' => $propertyType->id,
//             'name' => $propertyType->name,
//             'slug' => $propertyType->slug,
//             'image' => $propertyType->image,
//             'property_name' => $property->name // Include the property name in the response
//         ], 201);
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

        // Iterate over each property type and extract id, name, slug, property name, and image
        foreach ($propertyTypes as $propertyType) {
            $propertyName = $propertyType->property ? $propertyType->property->name : null;

            $propertyTypesData[] = [
                'id' => $propertyType->id,
                'name' => $propertyType->name,
                'slug' => $propertyType->slug,
                'property_name' => $propertyName,
                'image' => $propertyType->image
            ];
        }

        // Return the property type data as JSON response
        return response()->json($propertyTypesData);
    } catch (\Throwable $th) {
        // Handle any exceptions and return an error response
        return response()->json(['error' => $th->getMessage()], 500);
    }
}



public function update(Request $request)
{
    try {
        $id = $request->id;
        // Find the property type by ID
        $propertyType = PropertyType::findOrFail($id);

        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Allow null or valid image file
            'property_id' => 'required|exists:properties,id', // Ensure property_id exists in properties table
        ]);

        // If image is provided, handle file upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName(); // Unique filename
            $imagePath = $image->storeAs('images/property_types', $imageName);
            $validatedData['image'] = $imagePath;
        }

        // Update the property type with the validated data, including property_id
        $propertyType->update($validatedData);

        // Return the updated property type along with its details as JSON response
        return response()->json([
            'message' => 'Property type data updated successfully',
            'Propertytype data' => [
                'id' => $propertyType->id,
                'name' => $propertyType->name,
                'slug' => $propertyType->slug,
                'image' => $propertyType->image,
                'property-name' => $propertyType->property->name // Assuming 'property' is a property in PropertyType model
            ]
        ]);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        // Handle the case where the ID is not found
        return response()->json(['error' => 'Property type not found.'], 404);
    } catch (\Throwable $th) {
        // Handle any other exceptions and return an error response
        return response()->json(['error' => $th->getMessage()], 500);
    }
}




public function destroy(Request $request)
    {
        try {
            $id=$request->id;
            $property = PropertyType::findOrFail($id); // Find the property by ID

            $property->delete(); // Delete the property

            return response()->json(['message' => 'Property type deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


}
