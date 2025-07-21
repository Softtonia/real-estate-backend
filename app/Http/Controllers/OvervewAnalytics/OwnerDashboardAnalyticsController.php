<?php

namespace App\Http\Controllers\OvervewAnalytics;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OwnerDashboardAnalyticsController extends Controller
{
    //

     // this is for overview
    public function ownerDashboardAnalytics(Request $request)
    {
        try {

            $agentIds = User::where('role_id', 3)->pluck('id')->toArray();
            $companyIds = User::where('role_id', 4)->pluck('id')->toArray();
            $consultancyIds = User::where('role_id', 5)->pluck('id')->toArray();
            $developerIds = User::where('role_id', 6)->pluck('id')->toArray();


            $totalAgentProjectCount = PropertyList::whereIn('user_id', $agentIds)->count();
            $totalCompanyProjectCount = PropertyList::whereIn('user_id', $companyIds)->count();
            $totalConsultancyProjectCount = PropertyList::whereIn('user_id', $consultancyIds)->count();
            $totalDeveloperProjectCount = PropertyList::whereIn('user_id', $developerIds)->count();


            // Construct the return data
            $return = [
                'total_agent_property_count' => $totalAgentProjectCount,
                'total_company_property_count' => $totalCompanyProjectCount,
                'total_consultancy_property_count' => $totalConsultancyProjectCount,
                'total_developer_property_count' => $totalDeveloperProjectCount,
            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

}
