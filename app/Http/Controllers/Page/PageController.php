<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PageController extends Controller
{
    // Get all pages
    public function index()
    {
        $pages = Page::all();
        return response()->json([
            'status' => true,
            'message' => 'Pages retrieved successfully',
            'data' => $pages
        ], 200);
    }

    //  Get single page by ID
    public function show($id)
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json([
                'status' => false,
                'message' => 'Page not found'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'message' => 'Page retrieved successfully',
            'data' => $page
        ], 200);
    }

    //  Create new page
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'required|string|unique:pages,page',
            'title' => 'required|string',
            'content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $page = Page::create($validator->validated());

        return response()->json([
            'status' => true,
            'message' => 'Page created successfully',
            'data' => $page
        ], 201);
    }

    //  Update page by ID
    public function update(Request $request, $id)
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json([
                'status' => false,
                'message' => 'Page not found'
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'required|string|unique:pages,page,' . $id,
            'title' => 'required|string',
            'content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $page->update($validator->validated());

        return response()->json([
            'status' => true,
            'message' => 'Page updated successfully',
            'data' => $page
        ], 200);
    }

    //  Delete page by ID
    public function destroy($id)
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json([
                'status' => false,
                'message' => 'Page not found'
            ], 200);
        }

        $page->delete();

        return response()->json([
            'status' => true,
            'message' => 'Page deleted successfully'
        ], 200);
    }

    // Bulk Delete page by ID

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:pages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $ids = $request->ids;

        $deleted = Page::whereIn('id', $ids)->delete();

        return response()->json([
            'status' => true,
            'message' => "$deleted page(s) deleted successfully",
            'deleted_ids' => $ids
        ], 200);
    }


    // Search Pages 15-07-2025

    public function searchPage(Request $request)
    {
        try {
            $query = Page::query();

            // Only search in `page` and `title` fields
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('page', 'like', "%$search%")
                        ->orWhere('title', 'like', "%$search%");
                });
            }

            $pages = $query->latest()->get();

            return response()->json([
                'status' => true,
                'data' => $pages
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // check page uniqueness

    public function checkUnique(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $pageSlug = $request->page;
        $exists = Page::where('page', $pageSlug)->exists();

        if ($exists) {
            return response()->json([
                'status' => true,
                'unique' => false,
                'message' => 'This page is already exists.'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'unique' => true,
            'message' => "The page '{$pageSlug}' is available."
        ], 200);
    }




}
