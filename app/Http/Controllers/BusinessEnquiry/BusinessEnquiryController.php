<?php

namespace App\Http\Controllers\BusinessEnquiry;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BusinessEnquiry;
use Validator;


class BusinessEnquiryController extends Controller
{
    //
     public function index(Request $request)
    {
        // Default per page = 10, you can pass ?per_page=20 in request
        $perPage = $request->get('per_page', 10);

        $enquiries = BusinessEnquiry::latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Business enquiries fetched successfully.',
            'data' => $enquiries->items(),
            'meta' => [
                'current_page' => $enquiries->currentPage(),
                'from' => $enquiries->firstItem(),
                'last_page' => $enquiries->lastPage(),
                'per_page' => $enquiries->perPage(),
                'to' => $enquiries->lastItem(),
                'total' => $enquiries->total(),
            ],
            'links' => [
                'first' => $enquiries->url(1),
                'last' => $enquiries->url($enquiries->lastPage()),
                'prev' => $enquiries->previousPageUrl(),
                'next' => $enquiries->nextPageUrl(),
            ]
        ]);
    }


   
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'nullable|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'phone_no'   => 'required|string|max:15',
            'email'      => 'nullable|email|max:255',
            'message'    => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $enquiry = BusinessEnquiry::create($request->only([
            'first_name', 'last_name', 'phone_no', 'email', 'message'
        ]));

        return response()->json([
            'status' => true,
            'message' => 'Business enquiry submitted successfully.',
            'data' => $enquiry
        ]);
    }

    
    public function show($id)
    {
        $enquiry = BusinessEnquiry::find($id);

        if (!$enquiry) {
            return response()->json([
                'status' => false,
                'message' => 'Business enquiry not found.'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'data' => $enquiry
        ]);
    }

    
    public function destroy($id)
    {
        $enquiry = BusinessEnquiry::find($id);

        if (!$enquiry) {
            return response()->json([
                'status' => false,
                'message' => 'Business enquiry not found.'
            ], 200);
        }

        $enquiry->delete();

        return response()->json([
            'status' => true,
            'message' => 'Business enquiry deleted successfully.'
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:business_enquiries,id' // table name matches migration
        ]);

        $deletedCount = BusinessEnquiry::whereIn('id', $request->ids)->delete();

        if ($deletedCount === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No enquiries found to delete.'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'message' => "$deletedCount enquiries deleted successfully."
        ]);
    }


}
