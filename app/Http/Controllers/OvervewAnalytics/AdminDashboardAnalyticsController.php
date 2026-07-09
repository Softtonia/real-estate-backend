<?php

namespace App\Http\Controllers\OvervewAnalytics;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDashboardAnalyticsController extends Controller
{
    public function adminDashboardAnalytics(Request $request)
    {
        try {
            $return = [
                'total_properties' => $this->countDynamicPostsByPostType([
                    'property-listing',
                ]),

                'total_project' => $this->countDynamicPostsByPostType([
                    'project-listing',
                    'project',
                ]),

                'total_developer' => $this->countDynamicPostsByPostType([
                    'developer-listing',
                ]),

                'total_users' => User::where('role_id', '!=', 1)->count(),

                'property_inquiries' => $this->countPropertyInquiries(),

                'total_tickets' => Ticket::count(),
            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    private function countDynamicPostsByPostType(array $postTypeSlugs): int
    {
        if (! Schema::hasTable('post_types') || ! Schema::hasTable('dynamic_posts')) {
            return 0;
        }

        $postTypeIds = DB::table('post_types')
            ->whereIn('slug', $postTypeSlugs)
            ->pluck('id')
            ->toArray();

        if (empty($postTypeIds)) {
            return 0;
        }

        $query = DB::table('dynamic_posts');

        if (Schema::hasColumn('dynamic_posts', 'post_type_id')) {
            $query->whereIn('post_type_id', $postTypeIds);
        } elseif (Schema::hasColumn('dynamic_posts', 'post_type_slug')) {
            $query->whereIn('post_type_slug', $postTypeSlugs);
        } elseif (Schema::hasColumn('dynamic_posts', 'post_type')) {
            $query->whereIn('post_type', $postTypeSlugs);
        } else {
            return 0;
        }

        return $query->count();
    }

    private function countPropertyInquiries(): int
    {
        /*
         * Priority wise count.
         * Keep your actual enquiry table name first.
         */
        $possibleTables = [
            'property_inquiries',
            'property_enquiries',
            'business_enquiries',
            'contact_us_leads',
        ];

        foreach ($possibleTables as $table) {
            if (Schema::hasTable($table)) {
                return DB::table($table)->count();
            }
        }

        return 0;
    }
}