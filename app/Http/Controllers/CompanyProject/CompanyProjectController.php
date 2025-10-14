<?php

namespace App\Http\Controllers\CompanyProject;

use App\Http\Controllers\Controller;
use App\Models\ProjectList;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyProjectController extends Controller
{

    // this is for listing by userId
   


     // this is for fetch listing assigned projects of company
    public function fetchAssignedProjectOfCompany(Request $request)
    {
        try {
            // if ($request->header('api-token') == '') {
            //     return response()->json(['error' => 'Please enter api token first.'], 422);
            // }

            // $requestToken = $request->header('api-token');
            // $userData = User::where('api_token', $requestToken)->first();

            // // Validate that the user exists in the database
            // if (!$userData) {
            //     return response()->json(['error' => 'Company not found'], 404);
            // }

            // $userId = $userData->id;
            $companyUser = Auth::user();

            if (!$companyUser) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $userId = $companyUser->id;
            // Fetch distinct consultancies assigned to the company
            $consultancies = CompanyConsultancyProject::where('company_id', $userId)
                ->where('type', 'company-consultancy')
                ->with('consultancy')
                ->get()
                ->groupBy('consultancy_id');

            $returnData = [];
            $returnData['company'] = $companyUser;
            $returnData['consultancies'] = [];

            foreach ($consultancies as $consultancyId => $projects) {
                $consultancyData = [
                    'consultancy' => $projects->first()->consultancy,
                    'assigned_projects_count' => $projects->count()
                ];

                $returnData['consultancies'][] = $consultancyData;
            }

            return response()->json(['data' => $returnData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // this is for fetch listing project details of consultancy & company
    public function viewProjectDetailsOfCompany(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter API token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $userId = $userData->id;
            $consultancyId = $request->input('consultancy_id'); // Assuming company_id is passed as a query parameter

            $companyConsultancyProjects = CompanyConsultancyProject::with('company')
                ->where('company_id', $userId)
                ->where('consultancy_id', $consultancyId)
                ->where('type', 'company-consultancy')
                ->get();

            // Check if projects exist
            if ($companyConsultancyProjects->isEmpty()) {
                return response()->json(['error' => 'No projects found for this consultancy.'], 404);
            }

            // Fetch detailed project data
            $projectDetails = $companyConsultancyProjects->map(function ($ccProject) {
                return $this->getProjectDetailsOfCompany($ccProject->id);
            });

            return response()->json(['projects' => $projectDetails], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }
}
