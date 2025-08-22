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
    public function getCompanyProjectListing(Request $request)
    {
        try {

            // if ($request->header('api-token') == '') {
            //     return response()->json(['error' => 'Please enter api token first.'], 422);
            // }

            // $requestToken = $request->header('api-token');

            // $userId = null;

            // $userData = User::where('api_token', $requestToken)->first();

            // // Validate that the user exists in the database
            // if (!$userData) {
            //     return response()->json(['error' => 'Company not found'], 404);
            // }

            $companyUser = Auth::user();

            if (!$companyUser) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $userId = $companyUser->id;
            // $userId = $userData->id;


            $baseURL = config('app.url');
            $basePath = public_path();

            $projects = ProjectList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])->where('user_id', $userId)->get();

            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $projectData = [
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $projectData;
            });

            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


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
