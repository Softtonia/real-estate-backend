<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FaqCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class FaqCategoryController extends Controller
{
    // Store the data
    public function store(Request $request)
    {
        try {
            // Validate the request data
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:faq_categories,name',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $data = $request->only('name');
            $data['slug'] = Str::slug($request->name);

            $result = FaqCategory::create($data);

            $returnRes = [
                'status' => true,
                'message' => 'Data added successfully.',
                'data' => $result,
            ];

            return response()->json($returnRes, 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // List all categories
    public function index(Request $request)
    {
        try {
            $data = FaqCategory::all();
            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // Update the record
    public function update(Request $request)
    {
        try {
            $id =  $request->id;
            // Find the existing category by ID
            $faqcat = FaqCategory::findOrFail($id);

            // Validate the request data
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:faq_categories,name,' . $id,
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $data = $request->only('name');
            $data['slug'] = Str::slug($request->name);

            // Update the category
            $faqcat->update($data);

            // Prepare the response
            $returnRes = [
                'status' => true,
                'message' => 'Data updated successfully.',
                'data' => $faqcat,
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
            $faqcat = FaqCategory::find($id);

            if (!$faqcat) {
                return response()->json(['message' => 'Data not found'], 404);
            }

            // Delete the category
            $faqcat->delete();

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
            $data = FaqCategory::where('id', $request->id)->first();
            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // Bulk delete

    public function bulkDelete(Request $request)
    {
        try {
            //  Validate input
            $validated = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|distinct|exists:faq_categories,id',
            ]);

            $ids = $validated['ids'];

            //  Delete inside a transaction for safety
            DB::transaction(function () use ($ids) {
                FaqCategory::whereIn('id', $ids)->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Selected categories deleted successfully.',
                'deleted' => count($ids),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            // Automatic 422 with messages is fine, but mimic your style if you prefer:
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $ve->errors(),
            ], 422);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
