<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class servicescontroller extends Controller
{
    public function store(Request $request)
{
    try {
        // Get the bearer token from the request header
        $bearerToken = $request->bearerToken();
       

        // Check if the bearer token exists
        if (!$bearerToken) {
            return response()->json(['error' => 'Bearer token missing'], 401);
        }

        // Find the user based on the bearer token
        $user = DB::table('users')->where('api_token', $bearerToken)->first();
       
        // Check if user exists
        if (!$user) {
            return response()->json(['error' => 'Token not authenticated'], 401);
        }
        // Validate the request data
        $validatedData = $request->validate([
            'title' => 'required|string|max:255|unique:services',
            'content' => 'required',
            
        ]);

        // Create the service
        $service = Service::create($validatedData);

        return response()->json([
            'message' => 'Service created successfully',
            'id' => $service->id,
            'title' => $service->title,
            'content' => $service->content,
            'created_at' => $service->created_at,
            'updated_at' => $service->updated_at,
        ], 201);
    } catch (ValidationException $e) {
        // Handle validation error for duplicate title
        $errors = $e->validator->errors()->all();
        return response()->json(['error' => $errors[0]], 422);
    }
}

public function index()
{
    // Retrieve all services
    $services = Service::all();

    // Return the list of services as JSON response
    return response()->json(['services' => $services]);
}


public function update(Request $request)
{
    // Get the bearer token from the request header
    $bearerToken = $request->bearerToken();
    
    // Check if the bearer token exists
    if (!$bearerToken) {
        return response()->json(['error' => 'Bearer token missing'], 401);
    }

    // Find the user based on the bearer token
    $user = User::where('api_token', $bearerToken)->first();
   
    // Check if user exists
    if (!$user) {
        return response()->json(['error' => 'Token not authenticated'], 401);
    }

    // Validate the request data
    $validatedData = $request->validate([
        'title' => 'required|string|max:255',
        'content' => 'required|string',
    ]);

    // Check if 'id' is present in the request
    if (!$request->has('id')) {
        return response()->json(['error' => 'ID is required'], 400);
    }

    // Find the service
    $service = Service::find($request->id);

    // Check if service exists
    if (!$service) {
        return response()->json(['error' => 'Service not found'], 404);
    }

    // Update the service
    $service->update([
        'title' => $validatedData['title'],
        'content' => $validatedData['content'],
    ]);

    return response()->json(['message' => 'Service updated successfully', 'service' => $service]);
}



public function delete(Request $request)
{
    try {
        // Get the bearer token from the request header
        $bearerToken = $request->bearerToken();
           
        // Check if the bearer token exists
        if (!$bearerToken) {
            return response()->json(['error' => 'Bearer token missing'], 401);
        }

        // Find the user based on the bearer token
        $user = DB::table('users')->where('api_token', $bearerToken)->first();
       
        // Check if user exists
        if (!$user) {
            return response()->json(['error' => 'Token not authenticated'], 401);
        }
        
        // Validate the request data
        $request->validate([
            'id' => 'required|exists:services,id',
        ]);

        // Find the service
        $id = $request->id;
        $service = Service::findOrFail($id);

        // Delete the service
        $service->delete();

        return response()->json(['message' => 'Service deleted successfully']);
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Service not found'], 404);
    }
}



}
