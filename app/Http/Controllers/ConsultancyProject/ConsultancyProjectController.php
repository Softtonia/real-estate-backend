<?php

namespace App\Http\Controllers\ConsultancyProject;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConsultancyProjectController extends Controller
{
    //
    // this is for fetch listing total assigned projects  of consultancy
    public function fetchTotalAssignedProjectToConsultancy(Request $request)
    {
        try {
            // if ($request->header('api-token') == '') {
            //     return response()->json(['error' => 'Please enter api token first.'], 422);
            // }

            // $requestToken = $request->header('api-token');
            // $userData = User::where('api_token', $requestToken)->first();

            // // Validate that the user exists in the database
            // if (!$userData) {
            //     return response()->json(['error' => 'Consultancy not found'], 404);
            // }

            // $userId = $userData->id;


            $companyUser = Auth::user();

            if (!$companyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $companyUser->id;
            $companyConsultancyProjects = CompanyConsultancyProject::with('company')
                ->where('consultancy_id', $userId)
                ->where('type', 'company-consultancy')
                ->get();

            $companies = $companyConsultancyProjects->map(function ($ccProject) {
                return $ccProject->company;
            })->unique('id')->values();

            $returnData = [
                'consultancy' => $companyUser,
                'companies' => []
            ];

            foreach ($companies as $company) {
                $companyProjects = $companyConsultancyProjects->where('company_id', $company->id);
                $companyProjectsCount = $companyProjects->count();
                $projectsData = $companyProjects->map(function ($ccProject) {
                    return $this->getProjectDataConsultancy($ccProject->project_id);
                });

                $returnData['companies'][] = [
                    'company' => $company,
                    'assigned_projects_count' => $companyProjectsCount,
                    'projects' => $projectsData
                ];
            }

            return response()->json(['data' => $returnData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

     // this is for fetch listing total assigned projects  of consultancy
    public function fetchConsultancyTotalAssignedProjects(Request $request)
    {
        try {
            // Check if the API token is present in the request headers
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter API token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $userData->id;

            // Fetch all projects assigned to this consultancy
            $projectsData = CompanyConsultancyProject::where('consultancy_id', $userId)
                ->where('type', 'company-consultancy')
                ->get()
                ->map(function ($ccProject) {
                    return $this->getProjectDataConsultancy($ccProject->project_id);
                })
                ->filter(function ($projectData) {
                    return !is_null($projectData);
                });

            // Return only the projects data
            return response()->json(['data' => $projectsData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }




    // this is for fetch listing total projects of consultancy
    public function fetchTotalProjectOfConsultancy(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $userData->id;
            $companyConsultancyProjects = CompanyConsultancyProject::with('project')
                ->where('consultancy_id', $userId)
                ->where('type', 'company-consultancy')
                ->get();

            $returnData = [
                'consultancy' => $userData,
                'projects' => []
            ];

            foreach ($companyConsultancyProjects as $ccProject) {
                $returnData['projects'][] = $this->getTotalProjectDataConsultancy($ccProject->project_id);
            }

            return response()->json(['data' => $returnData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // this is for fetch listing project details of consultancy & company
    public function viewProjectDetailsOfConsultancy(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter API token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $userData->id;
            $companyId = $request->input('company_id'); // Assuming company_id is passed as a query parameter

            $companyConsultancyProjects = CompanyConsultancyProject::with('company')
                ->where('consultancy_id', $userId)
                ->where('company_id', $companyId)
                ->where('type', 'company-consultancy')
                ->get();

            // Check if projects exist
            if ($companyConsultancyProjects->isEmpty()) {
                return response()->json(['error' => 'No projects found for this company.'], 404);
            }

            // Fetch detailed project data
            $projectDetails = $companyConsultancyProjects->map(function ($ccProject) {
                return $this->getProjectDetailsOfConsultancy($ccProject->id);
            });

            return response()->json(['projects' => $projectDetails], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // this is for assign project to agent by consultancy
    public function assignProjectToAgentByConsultancy(Request $request)
    {
        try {
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userId = $user->id;

            $role = Role::find($user->role_id);
            if (!$role || $role->name !== 'consultancy') {
                return response()->json(['error' => 'User does not have the required role.'], 400);
            }
            $userId = $user->id;
            $project_ids = explode(',', $request->project_id);
            $agent_id = $request->agent_id;

            foreach ($project_ids as $project_id) {
                $existingEntry = CompanyConsultancyProject::where('consultancy_id', $userId)
                    ->where('agent_id', $agent_id)
                    ->where('project_id', $project_id)
                    ->where('type', 'consultancy-agent')
                    ->first();

                if ($existingEntry) {
                    return response()->json(['message' => 'Project with ID ' . $project_id . ' already assigned to this agent'], 409);
                }
            }

            foreach ($project_ids as $project_id) {
                $insertData = [
                    'consultancy_id' => $userId,
                    'agent_id' => $agent_id,
                    'project_id' => $project_id,
                    'type' => 'consultancy-agent'
                ];

                CompanyConsultancyProject::create($insertData);
            }

            return response()->json(['message' => 'Project assigned successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
        }
    }




}
