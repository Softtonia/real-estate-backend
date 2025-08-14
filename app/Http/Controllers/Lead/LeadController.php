<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LeadController extends Controller
{

    public function index()
    {
        $leads = Lead::with(['property', 'project', 'developer', 'user'])->get();

        return response()->json([
            'success' => true,
            'data' => $leads,
            'message' => 'Leads retrieved successfully'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'message' => 'nullable|string',
            'property_id' => 'nullable|exists:properties_listing,id',
            'project_id' => 'nullable|exists:project_listings,id',
            'developer_id' => 'nullable|exists:developer_listings,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        // Ensure at least one relational field is provided
        if (empty($request->property_id) && empty($request->project_id) &&
            empty($request->developer_id) && empty($request->user_id)) {
            return response()->json([
                'success' => false,
                'errors' => ['relation_error' => 'At least one of Property, Project, Developer or User must be selected.'],
                'message' => 'Validation failed'
            ], 422);
        }

        $lead = Lead::create($request->all());

        return response()->json([
            'success' => true,
            'data' => $lead->load(['property', 'project', 'developer', 'user']),
            'message' => 'Lead created successfully'
        ], 201);
    }

    /**
     * Display the specified resource by ID.
     */
    public function show($id)
    {
        $lead = Lead::with(['property', 'project', 'developer', 'user'])->find($id);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $lead,
            'message' => 'Lead retrieved successfully'
        ]);
    }

    /**
     * Update the specified resource by ID.
     */
    public function update(Request $request, $id)
    {
        $lead = Lead::find($id);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'message' => 'nullable|string',
            'property_id' => 'nullable|exists:properties_listing,id',
            'project_id' => 'nullable|exists:project_listings,id',
            'developer_id' => 'nullable|exists:developer_listings,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        // Check if at least one relational field exists (either in request or already in model)
        $hasRelation = !empty($request->property_id) || !empty($request->project_id) ||
                       !empty($request->developer_id) || !empty($request->user_id) ||
                       !empty($lead->property_id) || !empty($lead->project_id) ||
                       !empty($lead->developer_id) || !empty($lead->user_id);

        if (!$hasRelation) {
            return response()->json([
                'success' => false,
                'errors' => ['relation_error' => 'At least one of Property, Project, Developer or User must be selected.'],
                'message' => 'Validation failed'
            ], 422);
        }

        $lead->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $lead->fresh(['property', 'project', 'developer', 'user']),
            'message' => 'Lead updated successfully'
        ]);
    }

    /**
     * Remove the specified resource by ID.
     */
    public function destroy($id)
    {
        $lead = Lead::find($id);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        $lead->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully'
        ]);
    }
}
