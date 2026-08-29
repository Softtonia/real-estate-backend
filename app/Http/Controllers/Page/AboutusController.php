<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use App\Models\AboutUs;
use Illuminate\Http\Request;
use App\Models\User;
use DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AboutUsController extends Controller
{




    // public function storeOrUpdate(Request $request): JsonResponse
    // {
    //     // Validation
    //     $validator = Validator::make($request->all(), [
    //         'page_title' => 'nullable|string|max:255',
    //         'about_urbanrealities' => 'nullable|string',
    //         'what_we_offer' => 'nullable|string',
    //         'for_buyers_renters' => 'nullable|string',
    //         'for_sellers_landlords' => 'nullable|string',
    //         'our_mission_and_vision' => 'nullable|string',
    //         'seo_title' => 'nullable|string|max:255',
    //         'seo_description' => 'nullable|string|max:500',
    //         'seo_keywords' => 'nullable|string|max:500',
    //         'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
    //     ], [
    //         'page_title.max' => 'Page title should not exceed 255 characters.',
    //         'seo_title.max' => 'SEO title should not exceed 255 characters.',
    //         'seo_description.max' => 'SEO description should not exceed 500 characters.',
    //         'seo_keywords.max' => 'SEO keywords should not exceed 500 characters.',
    //         'featured_image.image' => 'Featured image must be an image file.',
    //         'featured_image.mimes' => 'Featured image must be of type: jpeg, png, jpg, gif, webp.',
    //         'featured_image.max' => 'Featured image size must not exceed 2MB.',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Validation errors occurred.',
    //             'errors' => $validator->errors()->toArray(), // ✅ array format
    //             'first_error' => $validator->errors()->first(), // ✅ single error
    //         ], 422);
    //     }

    //     try {
    //         $validated = $validator->validated();

    //         // Static slug
    //         $validated['slug'] = 'about-us'; // ✅ static slug

    //         // Single record assumption
    //         $aboutUs = AboutUs::first();

    //         // Handle Image Upload
    //         if ($request->hasFile('featured_image')) {
    //             if ($aboutUs && $aboutUs->featured_image && File::exists(public_path($aboutUs->featured_image))) {
    //                 File::delete(public_path($aboutUs->featured_image));
    //             }

    //             $file = $request->file('featured_image');
    //             $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
    //             $file->move(public_path('uploads/pages/about_us/featured_image'), $filename);
    //             $validated['featured_image'] = 'uploads/pages/about_us/featured_image/' . $filename;
    //         }

    //         if ($aboutUs) {
    //             $aboutUs->update($validated);
    //             $message = 'About Us page updated successfully.';
    //         } else {
    //             $aboutUs = AboutUs::create($validated);
    //             $message = 'About Us page created successfully.';
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => $message,
    //             'data' => $aboutUs
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Something went wrong while saving About Us data.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }


    public function storeOrUpdate(Request $request): JsonResponse
{
    // Static slug
    $staticSlug = 'about-us';

    // Validation
    $validator = Validator::make($request->all(), [
        'slug' => 'required|string', // ✅ slug required from request
        'page_title' => 'nullable|string|max:255',
        'about_urbanrealities' => 'nullable|string',
        'what_we_offer' => 'nullable|string',
        'for_buyers_renters' => 'nullable|string',
        'for_sellers_landlords' => 'nullable|string',
        'our_mission_and_vision' => 'nullable|string',
        'seo_title' => 'nullable|string|max:255',
        'seo_description' => 'nullable|string|max:500',
        'seo_keywords' => 'nullable|string|max:500',
        'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
    ], [
        'slug.required' => 'Slug is required.',
        'page_title.max' => 'Page title should not exceed 255 characters.',
        'seo_title.max' => 'SEO title should not exceed 255 characters.',
        'seo_description.max' => 'SEO description should not exceed 500 characters.',
        'seo_keywords.max' => 'SEO keywords should not exceed 500 characters.',
        'featured_image.image' => 'Featured image must be an image file.',
        'featured_image.mimes' => 'Featured image must be of type: jpeg, png, jpg, gif, webp.',
        'featured_image.max' => 'Featured image size must not exceed 2MB.',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'Validation errors occurred.',
            'errors' => $validator->errors()->toArray(),
            'first_error' => $validator->errors()->first(),
        ], 422);
    }

    try {
        $validated = $validator->validated();

        // ✅ Slug check (only allow if matches static slug)
        if ($validated['slug'] !== $staticSlug) {
            return response()->json([
                'status' => false,
                'message' => 'Your slug is not valid. Only "about-us" slug is allowed.',
            ], 422);
        }

        // Single record assumption
        $aboutUs = AboutUs::where('slug', $staticSlug)->first();

        // Handle Image Upload
        if ($request->hasFile('featured_image')) {
            if ($aboutUs && $aboutUs->featured_image && File::exists(public_path($aboutUs->featured_image))) {
                File::delete(public_path($aboutUs->featured_image));
            }

            $file = $request->file('featured_image');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/pages/about_us/featured_image'), $filename);
            $validated['featured_image'] = 'uploads/pages/about_us/featured_image/' . $filename;
        }

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

            if ($aboutUs->featured_image) {
                $aboutUs->featured_image_url = url($aboutUs->featured_image);
            } else {
                $aboutUs->featured_image_url = null;
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
