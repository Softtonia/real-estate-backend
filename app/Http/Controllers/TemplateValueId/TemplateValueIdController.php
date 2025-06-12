<?php

namespace App\Http\Controllers\TemplateValueId;

use App\Http\Controllers\Controller;
use App\Models\TemplateValueId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TemplateValueIdController extends Controller
{

    // GET all records
    public function index()
    {
        try {
            $data = TemplateValueId::all();

            if ($data->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'message' => 'No data found',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'status' => true,
                'message' => 'Data fetched successfully',
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while fetching data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // CREATE a new record
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'post_type' => 'required|in:project,property_list,developer_list',
            'status' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Generate slug from name
        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is unique
        while (TemplateValueId::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $validated['slug'] = $slug;

        $data = TemplateValueId::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Data created successfully',
            'data' => $data,
        ], 201);
    }

    // GET single record
    public function show(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:template_value_id,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = TemplateValueId::find($request->id);

            return response()->json([
                'status' => true,
                'message' => 'Data fetched successfully',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while fetching data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    // UPDATE record
    public function update(Request $request)
    {
        $id = $request->id;
        $data = TemplateValueId::find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data not found',
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:50',
            'slug' => 'nullable|string|max:100|unique:template_value_id,slug,' . $id,
            'post_type' => 'nullable|in:project,property_list,developer_list',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // If slug not provided but name is updated, generate a unique slug
        if (empty($validated['slug']) && !empty($validated['name'])) {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;

            while (TemplateValueId::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            $validated['slug'] = $slug;
        }

        $data->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Data updated successfully',
            'data' => $data,
        ]);
    }

    // DELETE record
    public function destroy(Request $request)
    {
        $id = $request->id;
        $data = TemplateValueId::find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data not found',
            ], 200);
        }

        $data->delete();

        return response()->json([
            'status' => true,
            'message' => 'Data deleted successfully',
        ]);
    }

    // Bulk Delete

    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:template_value_id,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ids = $request->ids;

        try {
            DB::beginTransaction();

            TemplateValueId::whereIn('id', $ids)->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected records deleted successfully.',
                'deleted_ids' => $ids
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete records',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
