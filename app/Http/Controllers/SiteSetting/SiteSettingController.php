<?php

namespace App\Http\Controllers\SiteSetting;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;

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
}
