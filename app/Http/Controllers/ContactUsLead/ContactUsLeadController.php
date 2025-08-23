<?php

namespace App\Http\Controllers\ContactUsLead;

use App\Http\Controllers\Controller;
use App\Models\ContactUsLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactUsLeadController extends Controller
{

    //  GET: /api/contact-us-leads (List with pagination & links)
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 10);

        $data = ContactUsLead::orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'All Contact Us Leads',
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ],
            'links' => [
                'first' => $data->url(1),
                'last' => $data->url($data->lastPage()),
                'prev' => $data->previousPageUrl(),
                'next' => $data->nextPageUrl(),
            ]
        ]);
    }

    //  POST: /api/contact-us-leads (Create new lead)
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email',
            'country_code' => ['required', 'string', 'max:5', 'regex:/^\+\d{1,4}$/'],
            'phone_number' => ['required', 'string', 'max:15', 'regex:/^\d{6,14}$/'],
            'message' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $lead = ContactUsLead::create($validator->validated());

        return response()->json([
            'status' => true,
            'message' => 'Lead created successfully',
            'data' => $lead,
        ], 201);
    }

    // ✅ GET: /api/contact-us-leads/{id} (Single lead)
    public function show($id)
    {
        $lead = ContactUsLead::find($id);

        if (!$lead) {
            return response()->json(['status' => false, 'message' => 'Lead not found'], 200);
        }

        return response()->json(['status' => true, 'message' => 'Lead Fetched Successfully', 'data' => $lead], 200);
    }

    // PUT: /api/contact-us-leads/{id} (Update lead)
    public function update(Request $request, $id)
    {
        $lead = ContactUsLead::find($id);

        if (!$lead) {
            return response()->json(['status' => false, 'message' => 'Lead not found'], 200);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|nullable|string|max:100',
            'email' => 'sometimes|required|email',
            'country_code' => ['sometimes', 'required', 'string', 'max:5', 'regex:/^\+\d{1,4}$/'],
            'phone_number' => ['sometimes', 'required', 'string', 'max:15', 'regex:/^\d{6,14}$/'],
            'message' => 'sometimes|nullable|string',
            'status' => 'sometimes|in:new,viewed,contacted,closed',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $lead->update($validator->validated());

        return response()->json([
            'status' => true,
            'message' => 'Lead updated successfully',
            'data' => $lead,
        ]);
    }

    //  DELETE: /api/contact-us-leads/{id} (Delete lead)
    public function destroy($id)
    {
        $lead = ContactUsLead::find($id);

        if (!$lead) {
            return response()->json(['status' => false, 'message' => 'Lead not found'], 200);
        }

        $lead->delete();

        return response()->json([
            'status' => true,
            'message' => 'Lead deleted successfully',
        ]);
    }


    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');

        // Get existing IDs to avoid deleting non-existent ones
        $existingIds = ContactUsLead::whereIn('id', $ids)->pluck('id')->toArray();

        // Filter out non-existent IDs
        $notFoundIds = array_diff($ids, $existingIds);

        // Delete only existing ones
        $deleted = ContactUsLead::whereIn('id', $existingIds)->delete();

        return response()->json([
            'status' => true,
            'message' => $deleted . ' leads deleted successfully',
            'not_found_ids' => array_values($notFoundIds), // return missing IDs
        ]);
    }


    public function updateStatus(Request $request, $id)
    {
        $lead = ContactUsLead::find($id);

        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'Lead not found'
            ], 200);
        }

        $request->validate([
            'status' => 'required|in:new,viewed,contacted,closed',
        ]);

        $lead->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Status updated successfully',
            'data' => $lead
        ]);
    }

    public function contactUsLeadSearch(Request $request)
    {
        $request->validate([
            'search' => 'required|string',
            'per_page' => 'nullable|integer',
        ]);

        $search = $request->input('search');
        $perPage = $request->input('per_page', 10); // default 10 per page

        $leads = ContactUsLead::where('first_name', 'like', "%{$search}%")
            ->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")
            ->orWhere('phone_number', 'like', "%{$search}%")
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $leads
        ]);
    }

}
