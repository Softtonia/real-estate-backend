<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Builder;
use App\Models\Location;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
class Buildercontroller extends Controller
{
    public function store(Request $request)
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
    // Validate request data
    $validatedData = $request->validate([
    'location_id' => 'required|exists:locations,id',
    'properties_id' => 'required|exists:properties,id',
    'property_type_id' => 'required|exists:property_types,id',
    'builders_name' => 'required|string',
    'description' => 'nullable|string',
    'ongoing_project' => 'required|string',
    'city' => 'required|string',
    'rera' => 'required|string',
    ]);
    
    // Get the last builder's ID
    $lastBuilder = Builder::latest()->first();
    
    // Extract numeric part of the last builder's unique ID
    $lastId = $lastBuilder ? intval(substr($lastBuilder->builders_unique_id, 3)) : 0;
    
    // Generate unique builder ID
    $builderUniqueId = 'URB' . str_pad($lastId + 1, 3, '0', STR_PAD_LEFT);
    
    // Add generated builder unique ID to the validated data
    $validatedData['builders_unique_id'] = $builderUniqueId;
    
    // Create a new builder record
    $builder = Builder::create($validatedData);
    
    // Return a success response
    return response()->json([
    'message' => 'Builder created successfully',
    'builder' => $builder
    ], 201);
    } catch (ValidationException $e) {
    // Handle validation errors
    return response()->json(['error' => $e->errors()], 422);
    } catch (ModelNotFoundException $e) {
    // Handle model not found errors
    return response()->json(['error' => 'Resource not found'], 404);
    } catch (\Exception $e) {
    // Handle other unexpected errors
    return response()->json(['error' => 'Something went wrong'], 500);
    }
    }
    
    public function index()
    {
    // Load projects with the propertyType, location, and amenity relationships
    $builders = Builder::with(['propertyType', 'location','propertiesname'])->get();
    
    
    // Transform the collection to attach the property type name, location name, and amenity data
    $builders->transform(function ($builders) {
    // Check if propertyType relationship is loaded
    if ($builders->propertyType) {
    $builders->property_type_name = $builders->propertyType->name;
    } else {
    $builders->property_type_name = null; // or set it to a default value
    }
    
    // Check if location relationship is loaded
    if ($builders->status) {
    $builders->property_status = $builders->status->name;
    } else {
    $builders->property_status = null; // or set it to a default value
    }
    
    // Check if location relationship is loaded
    if ($builders->location) {
    $builders->location_name = $builders->location->name;
    } else {
    $builders->location_name = null; // or set it to a default value
    }
    
    
    // Check if property relationship is loaded
    if ($builders->propertiesname) {
    $builders->properties_name = $builders->propertiesname->name;
    } else {
    $builders->properties_name = null; // or set it to a default value
    }
    
    // unset($builders->propertyType, $builders->location); // Remove unnecessary attributes
    return $builders;
    });
    
    return $builders;
    }
    
    public function update(Request $request)
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
    $id = $request->id;
    
    // Find the builder by ID
    $builder = Builder::findOrFail($id);
    
    // Validate request data
    $validatedData = $request->validate([
    'location_id' => 'nullable|exists:locations,id',
    'properties_id' => 'nullable|exists:properties,id',
    'property_type_id' => 'nullable|exists:property_types,id',
    'builders_name' => 'nullable|string',
    'description' => 'nullable|string',
    'ongoing_project' => 'required|string',
    'city' => 'nullable|string',
    ]);
    
    // Update the builder record
    $builder->update($validatedData);
    
    // Return a success response
    return response()->json([
    'message' => 'Builder updated successfully',
    'data' => $builder->toArray()
    ], 200);
    } catch (ValidationException $e) {
    // Handle validation errors
    return response()->json(['error' => $e->errors()], 422);
    } catch (ModelNotFoundException $e) {
    // Handle model not found errors
    return response()->json(['error' => 'Resource not found'], 404);
    } catch (\Exception $e) {
    // Handle other unexpected errors
    return response()->json(['error' => 'Something went wrong'], 500);
    }
    }
    
    public function destroy(Request $request)
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
    // Find the builder by ID
    $id = $request->id;
    $builder = Builder::findOrFail($id);
    
    // Delete the builder record
    $builder->delete();
    
    // Return a success response
    return response()->json([
    'message' => 'Builder deleted successfully',
    
    ], 200);
    } catch (ModelNotFoundException $e) {
    // Handle model not found errors
    return response()->json(['error' => 'Builder not found'], 404);
    } catch (\Exception $e) {
    // Handle other unexpected errors
    return response()->json(['error' => 'Something went wrong'], 500);
    }
    }
    
    public function show(Request $request)
    {
    try {
    $id = $request->id;
    
    // Load the specific builder by ID with the propertyType, location, and amenity relationships
    $builder = Builder::with(['propertyType', 'location','propertiesname'])->find($id);
    
    if(!$builder) {
    // If the builder with the given ID is not found, return an appropriate response
    return response()->json(['error' => 'Builder not found'], 404);
    }
    
    // Transform the builder object to attach the property type name, location name, and amenity data
    $builderData = $builder->toArray();
    
    // Check if propertyType relationship is loaded
    if ($builder->propertyType) {
    $builderData['property_type_name'] = $builder->propertyType->name;
    } else {
    $builderData['property_type_name'] = null; // or set it to a default value
    }
    
    // Check if location relationship is loaded
    if ($builder->status) {
    $builderData['property_status'] = $builder->status->name;
    } else {
    $builderData['property_status'] = null; // or set it to a default value
    }
    
    // Check if location relationship is loaded
    if ($builder->location) {
    $builderData['location_name'] = $builder->location->name;
    } else {
    $builderData['location_name'] = null; // or set it to a default value
    }
    
    // Check if property relationship is loaded
    if ($builder->propertiesname) {
    $builderData['properties_name'] = $builder->propertiesname->name;
    } else {
    $builderData['properties_name'] = null; // or set it to a default value
    }
    
    return response()->json($builderData);
    } catch (\Exception $e) {
    // Handle any exceptions and return an error response
    return response()->json(['error' => $e->getMessage()], 500);
    }
    }
    
}
