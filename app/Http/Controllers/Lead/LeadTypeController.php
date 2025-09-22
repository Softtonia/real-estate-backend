<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Models\LeadType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LeadTypeController extends Controller
{

    // Get all lead types
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $perPage = $request->per_page ?? 15; // Default 15 results per page

        $leadTypes = LeadType::paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Lead Types fetched successfully',
            'data' => $leadTypes->items(),
            'pagination' => [
                'current_page' => $leadTypes->currentPage(),
                'per_page' => $leadTypes->perPage(),
                'total' => $leadTypes->total(),
                'last_page' => $leadTypes->lastPage(),
                'from' => $leadTypes->firstItem(),
                'to' => $leadTypes->lastItem(),
                'links' => [
                    'first' => $leadTypes->url(1),
                    'last' => $leadTypes->url($leadTypes->lastPage()),
                    'prev' => $leadTypes->previousPageUrl(),
                    'next' => $leadTypes->nextPageUrl(),
                ],
            ]
        ], 200);
    }

    // Get single lead type by id
    public function show($id)
    {
        $leadType = LeadType::find($id);
        if (!$leadType) {
            return response()->json(['message' => 'Lead Type not found'], 200);
        }
        return response()->json([
            'success' => true,
            'message' => 'Lead Type fetched successfully',
            'leadType' => $leadType
        ], 200);
    }

    // Create a new lead type
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'slug' => 'required|string|max:100|unique:lead_types,slug'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $leadType = LeadType::create($request->all());

        return response()->json([
            'message' => 'Lead Type created successfully',
            'data' => $leadType
        ], 201);
    }

    // Update a lead type
    public function update(Request $request, $id)
    {
        $leadType = LeadType::find($id);
        if (!$leadType) {
            return response()->json(['message' => 'Lead Type not found'], 200);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'status' => 'sometimes|required|in:active,inactive',
            'slug' => 'sometimes|required|string|max:100|unique:lead_types,slug,' . $id
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $leadType->update($request->all());

        return response()->json([
            'message' => 'Lead Type updated successfully',
            'data' => $leadType
        ]);
    }

    // Delete a lead type
    public function destroy($id)
    {
        $leadType = LeadType::find($id);
        if (!$leadType) {
            return response()->json(['message' => 'Lead Type not found'], 200);
        }

        $leadType->delete();

        return response()->json(['message' => 'Lead Type deleted successfully']);
    }


    public function getSearchByName(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'required|string|min:2|max:100',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $perPage = $request->per_page ?? 10; // Default 15 results per page

        $leadTypes = LeadType::where('name', 'like', '%' . $request->search . '%')
            ->paginate($perPage);

        if ($leadTypes->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No lead types found matching your search'
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lead Types fetched successfully',
            'data' => $leadTypes->items(),
            'pagination' => [
                'current_page' => $leadTypes->currentPage(),
                'per_page' => $leadTypes->perPage(),
                'total' => $leadTypes->total(),
                'last_page' => $leadTypes->lastPage(),
                'from' => $leadTypes->firstItem(),
                'to' => $leadTypes->lastItem(),
                'links' => [
                    'first' => $leadTypes->url(1),
                    'last' => $leadTypes->url($leadTypes->lastPage()),
                    'prev' => $leadTypes->previousPageUrl(),
                    'next' => $leadTypes->nextPageUrl(),
                ],
            ]
        ], 200);
    }
    public function getSearchBySlug(Request $request)
    {
        $request->validate([
            'slug' => 'required|string'
        ]);

        $leadType = LeadType::where('slug', $request->slug)->first();

        if (!$leadType) {
            return response()->json([
                'success' => false,
                'message' => 'Lead Type not found'
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lead Type fetched successfully',
            'leadType' => $leadType
        ], 200);
    }


    public function checkSlugUnique(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Please enter a valid slug'
            ], 422);
        }

        $exists = LeadType::where('slug', $request->slug)->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Slug is already taken'
            ], 200);
        }

        return response()->json([
            'message' => 'Slug is available'
        ], 200);
    }


    public function searchLeadType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'required|string|min:2|max:100',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $perPage = $request->per_page ?? 10;

        $leadTypes = LeadType::where('name', 'like', '%' . $request->search . '%')
            ->orWhere('slug', 'like', '%' . $request->search . '%')
            ->paginate($perPage);

        if ($leadTypes->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No lead types found matching your search'
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lead Types fetched successfully',
            'data' => $leadTypes->items(),
            'pagination' => [
                'current_page' => $leadTypes->currentPage(),
                'per_page' => $leadTypes->perPage(),
                'total' => $leadTypes->total(),
                'last_page' => $leadTypes->lastPage(),
                'from' => $leadTypes->firstItem(),
                'to' => $leadTypes->lastItem(),
                'links' => [
                    'first' => $leadTypes->url(1),
                    'last' => $leadTypes->url($leadTypes->lastPage()),
                    'prev' => $leadTypes->previousPageUrl(),
                    'next' => $leadTypes->nextPageUrl(),
                ],
            ]
        ], 200);
    }


}
