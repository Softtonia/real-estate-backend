<?php

namespace App\Http\Controllers\SiteSetting;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;


class SiteSettingController extends Controller
{

    public function siteSetting(Request $request)
    {
        try {
            $setting = SiteSetting::first();

            if ($setting) {
                $response = $setting->toArray();

                $response['website_logo'] = $setting->website_logo
                    ? url('uploads/website_logo/' . $setting->website_logo)
                    : null;

                $response['mobile_logo'] = $setting->mobile_logo
                    ? url('uploads/mobile_logo/' . $setting->mobile_logo)
                    : null;

                $response['favicon'] = $setting->favicon
                    ? url('uploads/favicon/' . $setting->favicon)
                    : null;
            } else {
                // If no data, return all expected keys with null
                $response = [
                    'id' => null,
                    'ticket_prefix' => null,
                    'website_logo' => null,
                    'mobile_logo' => null,
                    'favicon' => null,
                    'site_name' => null,
                    'address' => null,
                    'mobile_number' => null,
                    'email' => null,
                    'copyright_text' => null,
                    'disclaimer' => null,
                    'site_short_description' => null,
                    'subscribe_short_description' => null,
                    'facebook' => null,
                    'instagram' => null,
                    'twitter' => null,
                    'created_at' => null,
                    'updated_at' => null,
                ];
            }

            return response()->json(['data' => $response]);

        } catch (\Exception $e) {
            \Log::error('Error fetching site settings: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching site settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }


     public function updateSiteSetting(Request $request)
    {
        // Manual validator
        $validator = Validator::make($request->all(), [
            'website_logo' => 'nullable|image|max:2048|mimes:png,jpg,jpeg',
            'mobile_logo' => 'nullable|image|max:2048|mimes:png,jpg,jpeg',
            'site_name' => 'nullable|string',
            'favicon' => 'nullable|image|max:2048|mimes:png,jpg,jpeg',
            'address' => 'nullable|string',
            'mobile_number' => 'nullable|numeric|digits:10',
            'email' => 'nullable|string|email',
            'copyright_text' => 'nullable|string',
            'disclaimer' => 'nullable|string',
            'site_short_description' => 'nullable|string',
            'subscribe_short_description' => 'nullable|string',
            'facebook' => 'nullable|string',
            'instagram' => 'nullable|string',
            'twitter' => 'nullable|string',
            'ticket_prefix' => 'nullable|string',
            'property_prefix' => 'nullable|string',
            'developer_prefix' => 'nullable|string',
            'project_prefix' => 'nullable|string',
            'for_general_mobile_number' => 'nullable|numeric|digits:10',
            'for_sales_mobile_number' => 'nullable|numeric|digits:10',
            'for_business_mobile_number' => 'nullable|numeric|digits:10',
        ]);

        // If validation fails, return JSON
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Get validated data
        $data = $validator->validated();

        // Handle website_logo upload
        if ($request->hasFile('website_logo')) {
            $file = $request->file('website_logo');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/website_logo'), $fileName);
            $data['website_logo'] = $fileName;
        }

        // Handle mobile_logo upload
        if ($request->hasFile('mobile_logo')) {
            $file = $request->file('mobile_logo');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/mobile_logo'), $fileName);
            $data['mobile_logo'] = $fileName;
        }

        // Handle favicon upload
        if ($request->hasFile('favicon')) {
            $file = $request->file('favicon');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/favicon'), $fileName);
            $data['favicon'] = $fileName;
        }

        // Update or create settings
        $setting = SiteSetting::first();
        if ($setting) {
            foreach ($data as $key => $value) {
                $setting->$key = $value;
            }
            $setting->save();
        } else {
            $setting = SiteSetting::create($data);
        }

        return response()->json([
            'status' => true,
            'message' => 'Settings updated successfully',
            'settings' => $setting
        ]);
    }
}
