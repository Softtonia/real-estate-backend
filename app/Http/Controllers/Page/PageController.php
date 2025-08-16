<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PageController extends Controller
{
    // Get all pages
    public function index()
    {
        $pages = Page::all()->map(function ($page) {
            if ($page->featured_image) {
                $page->featured_image_url = url($page->featured_image);
            } else {
                $page->featured_image_url = null;
            }
            return $page;
        });
        return response()->json([
            'status' => true,
            'message' => 'Pages retrieved successfully',
            'data' => $pages
        ], 200);
    }

    // Get single page by ID
    public function show($id)
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json([
                'status' => false,
                'message' => 'Page not found'
            ], 200);
        }

        if ($page->featured_image) {
            $page->featured_image_url = url($page->featured_image);
        } else {
            $page->featured_image_url = null;
        }

        return response()->json([
            'status' => true,
            'message' => 'Page retrieved successfully',
            'data' => $page
        ], 200);
    }

    // Create new page
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page_title' => 'required|string',
            'slug' => 'required|string|unique:pages,slug',
            'content' => 'required|string',
            'breadcrumb' => 'nullable|string',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()->toArray(), // clear array response
                'first_error' => $validator->errors()->first(), // single error message
            ], 422);
        }

        $data = $validator->validated();

        // Image Upload
        if ($request->hasFile('featured_image')) {
            $file = $request->file('featured_image');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/pages/featured_image'), $filename);
            $data['featured_image'] = 'uploads/pages/featured_image/' . $filename;
        }

        $page = Page::create($data);

        return response()->json([
            'status' => true,
            'message' => 'Page created successfully',
            'data' => $page
        ], 201);
    }

    // Update page by ID
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
            'page_title' => 'required|string',
            'slug' => 'required|string|unique:pages,slug,' . $page->id,
            'content' => 'required|string',
            'breadcrumb' => 'nullable|string',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()->toArray(), // clear array response
                'first_error' => $validator->errors()->first(), // single error message
            ], 422);
        }


        $data = $validator->validated();



        // If new image uploaded, delete old one
        if ($request->hasFile('featured_image')) {
            if ($page->featured_image && File::exists(public_path($page->featured_image))) {
                File::delete(public_path($page->featured_image));
            }
            $file = $request->file('featured_image');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/pages/featured_image'), $filename);
            $data['featured_image'] = 'uploads/pages/featured_image/' . $filename;
        }

        $page->update($data);

        return response()->json([
            'status' => true,
            'message' => 'Page updated successfully',
            'data' => $page
        ], 200);
    }

    // Delete page by ID
    public function destroy($id)
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json([
                'status' => false,
                'message' => 'Page not found'
            ], 200);
        }

        if ($page->featured_image && File::exists(public_path($page->featured_image))) {
            File::delete(public_path($page->featured_image));
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

        $pages = Page::whereIn('id', $request->ids)->get();

        foreach ($pages as $page) {
            if ($page->featured_image && File::exists(public_path($page->featured_image))) {
                File::delete(public_path($page->featured_image));
            }
            $page->delete();
        }

        return response()->json([
            'status' => true,
            'message' => count($request->ids) . " page(s) deleted successfully",
            'deleted_ids' => $request->ids
        ], 200);
    }

    // Search Pages
    public function searchPage(Request $request)
    {
        try {
            $query = Page::query();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('page_title', 'like', "%$search%")
                        ->orWhere('slug', 'like', "%$search%");
                });
            }

            $pages = $query->latest()->get();

            // featured_image ka full URL add karein
            $pages->transform(function ($page) {
                $page->featured_image_url = $page->featured_image
                    ? url($page->featured_image)
                    : null;
                return $page;
            });



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

    // Check page uniqueness
    public function checkUnique(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $slug = $request->slug;
        $exists = Page::where('slug', $slug)->exists();

        return response()->json([
            'status' => true,
            'unique' => !$exists,
            'message' => $exists
                ? 'A page with a similar slug already exists.'
                : "The slug you entered is available."
        ], 200);
    }



}
