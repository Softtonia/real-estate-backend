<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use App\Models\AboutUs;
use Illuminate\Http\Request;
use App\Models\Page;
use App\Models\User;
use DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AboutusController extends Controller
{

    public function update(Request $request)
    {
        try {
            // Find the page by pagename
            $page = Page::where('page', 'Aboutus')->first();

            if (!$page) {
                // If page doesn't exist, create a new one
                $page = new Page();
                $page->page = 'Aboutus';
            }

            // Update the page title
            $page->title = $request->input('title');

            // Update the page content
            $page->content = $request->input('content');

            // Save the changes
            $page->save();

            return response()->json(['success' => 'Page ' . ($page->wasRecentlyCreated ? 'created' : 'updated') . ' successfully']);
        } catch (\Throwable $th) {
            // Log the specific error message for debugging purposes
            \Log::error('Error updating page: ' . $th->getMessage());
            return response()->json(['error' => 'An error occurred while updating the page'], 500);
        }
    }


    public function index(Request $request)
    {
        try {
            // Fetch pages with the name
            $pages = Page::where('page', 'Aboutus')->get();

            // Check if there are any pages
            if ($pages->isEmpty()) {
                return response()->json(['error' => 'No pages found with the name "Aboutus"'], 404);
            }

            // Return the list of pages
            return response()->json(['pages' => $pages]);
        } catch (\Throwable $th) {
            // Log the specific error message for debugging purposes
            \Log::error('Error listing Aboutus pages: ' . $th->getMessage());
            return response()->json(['error' => 'An error occurred while listing Aboutus pages'], 500);
        }
    }

    ############### new code ###############


    public function storeOrUpdate(Request $request): JsonResponse
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'page_title' => 'nullable|string|max:255',
            'about_urbanrealities' => 'nullable|string',
            'what_we_offer' => 'nullable|string',
            'for_buyers_renters' => 'nullable|string',
            'for_sellers_landlords' => 'nullable|string',
            'our_mission_and_vision' => 'nullable|string',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
        ], [
            'page_title.max' => 'Page title should not exceed 255 characters.',
            'seo_title.max' => 'SEO title should not exceed 255 characters.',
            'seo_description.max' => 'SEO description should not exceed 500 characters.',
            'seo_keywords.max' => 'SEO keywords should not exceed 500 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors occurred.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $validated = $validator->validated();

            // Single record assumption
            $aboutUs = AboutUs::first();

            if ($aboutUs) {
                $aboutUs->update($validated);
                $message = 'About Us page updated successfully.';
            } else {
                $aboutUs = AboutUs::create($validated);
                $message = 'About Us page created successfully.';
            }

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $aboutUs
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while saving About Us data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch About Us content.
     */
    public function show(): JsonResponse
    {
        try {
            $aboutUs = AboutUs::first();

            if (!$aboutUs) {
                return response()->json([
                    'status' => false,
                    'message' => 'About Us page not found.'
                ], 200);
            }

            return response()->json([
                'status' => true,
                'data' => $aboutUs
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while fetching About Us data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
