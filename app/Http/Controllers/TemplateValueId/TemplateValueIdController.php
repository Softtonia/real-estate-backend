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
            $data = TemplateValueId::where('status',1)->get();

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
        $slug = Str::slug($validated['name']);

        //  Condition 1: Slug already exists globally (optional if DB unique)
        $slugExists = TemplateValueId::where('slug', $slug)->exists();

        if ($slugExists) {
            return response()->json([
                'status' => false,
                'message' => 'Slug already exists. Please change the "name" field.',
            ], 409);
        }

        //  Condition 2: Slug with same post_type exists
        $comboExists = TemplateValueId::where('slug', $slug)
            ->where('post_type', $validated['post_type'])
            ->exists();

        if ($comboExists) {
            return response()->json([
                'status' => false,
                'message' => 'This name already exists for the selected post type. Please change the name.',
            ], 409);
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

            $data = TemplateValueId::find($request->id)->where('status', 1);

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
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:50',
            'slug' => 'nullable|string|max:100',
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

        // Use existing post_type if not sent
        $postType = $validated['post_type'] ?? $data->post_type;

        // Slug logic
        if (!empty($validated['slug'])) {
            $slug = Str::slug($validated['slug']);
        } elseif (!empty($validated['name'])) {
            $slug = Str::slug($validated['name']);
        } else {
            $slug = $data->slug; // no change
        }

        // Check for duplicate slug + post_type combo (excluding current record)
        $exists = TemplateValueId::where('slug', $slug)
            ->where('post_type', $postType)
            ->where('id', '=', $id)
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => false,
                'message' => 'This name or slug already exists for the selected post type. Please change the name or slug.',
            ], 409);
        }

        $validated['slug'] = $slug;
        $validated['post_type'] = $postType;

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

// check uniqueness
    public function checkTemplateValueIdUniqueness(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $slug = Str::slug($request->slug); // Normalize slug input

        $exists = TemplateValueId::where('slug', $slug)->exists();

        return response()->json([
            'status' => !$exists,
            'message' => $exists ? 'Slug already exists.' : 'Slug is available.',
            'slug' => $slug,
        ]);
    }



    // get template value id by post_type

    public function getTemplateValueIdByPostType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'post_type' => 'required|in:project,property_list,developer_list',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = TemplateValueId::where('post_type', $request->post_type)->where('status',1)->get();

        if ($data->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data found for the given post_type.',
            ], 200);
        }

        return response()->json([
            'status' => true,
            'message' => 'Data fetched successfully.',
            'data' => $data,
        ]);
    }

}
