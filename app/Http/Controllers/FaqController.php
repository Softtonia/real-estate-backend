<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;


class FaqController extends Controller
{
    // Store the data
    public function store(Request $request)
    {
        try {
            // Validate Authorization Token
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }
    
            $authorizationHeader = $request->header('Authorization');
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }
    
            $requestToken = substr($authorizationHeader, 7);
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }
    
            $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();
            if (!$tokenExists) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }
    
            // Validate the request data
            $validator = Validator::make($request->all(), [
                'faq_category_id' => 'required|exists:faq_categories,id',
                'question' => 'required|string|max:255',
                'answer' => 'required|string',
                'display_order' => 'nullable|integer|unique:faqs,display_order',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            // Determine display order
            $displayOrder = $request->input('display_order');
            if ($displayOrder === null) {
                $maxOrder = DB::table('faqs')->max('display_order');
                $displayOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;
    
                while (Faq::where('display_order', $displayOrder)->exists()) {
                    $displayOrder++;
                }
            }
    
            $data = $request->only('faq_category_id', 'question', 'answer');
            $data['display_order'] = $displayOrder;
    
            $faq = Faq::create($data);
    
            return response()->json([
                'status' => true,
                'message' => 'Data added successfully.',
                'data' => $faq,
            ], 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
    
    // List all FAQs
    public function index(Request $request)
    {
        try {
            $data = Faq::with('faqcategory')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // Update the record
    public function update(Request $request)
    {
        try {
            $id = $request->id;
            // Find the existing FAQ by ID
            $faq = Faq::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'faq_category_id' => 'required|exists:faq_categories,id',
                'question' => 'required|string|max:255',
                'answer' => 'required|string',
                'display_order' => 'required|string|max:255|unique:faqs,display_order,' . $id,
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $data = $request->only('faq_category_id', 'question', 'answer', 'display_order');
            $faq->update($data);

            $returnRes = [
                'status' => true,
                'message' => 'Data updated successfully.',
                'data' => $faq,
            ];

            return response()->json($returnRes, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // Delete the record
    public function destroy(Request $request)
    {
        try {
            $id = $request->id;
            $faq = Faq::find($id);

            if (!$faq) {
                return response()->json(['message' => 'Data not found'], 404);
            }

            // Delete the FAQ
            $faq->delete();

            $returnRes = [
                'status' => true,
                'message' => 'Data deleted successfully.'
            ];

            return response()->json($returnRes, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // Get data by ID
    public function getdatabyId(Request $request)
    {
        try {
            $data = Faq::where('id', $request->id)->with('faqcategory')->first();
            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
}
