<?php

namespace App\Http\Controllers\AgentProject;

use App\Http\Controllers\Controller;
use App\Models\ProjectList;
use App\Models\User;
use Illuminate\Http\Request;

class AgentProjectController extends Controller
{
     // this is for fetch listing assigned projects of agent
    public function fetchAssignedProjectOfAgent(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $userId = $userData->id;

            // Fetch projects assigned to the agent by consultancy
            $consultancyProjects = CompanyConsultancyProject::where('agent_id', $userId)
                ->where('type', 'consultancy-agent')
                ->with('consultancy')
                ->get()
                ->groupBy('consultancy_id');

            $returnData = [];
            $returnData['agent'] = $userData;
            $returnData['consultancies'] = [];

            foreach ($consultancyProjects as $consultancyId => $projects) {
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


     // this is for get project data of consultancy
    private function getProjectDataConsultancy($project_id)
    {
        $baseURL = config('app.url');

        $project = ProjectList::with([
            'location',
            'user',
            'propertyType',
            'purpose',
            'property',
            'propertystatus',
            'customFieldValues.customField',
            'customFieldValues.customFieldOption'
        ])->find($project_id);

        if (!$project) {
            return null;
        }

        $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
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

            return [
                'custom_field_id' => $customField ? $customField->id : null,
                'field_type' => $customField ? $customField->field_type : null,
                'field_value' => $fieldValueArray,
                'field_name' => $customField ? $customField->field_name : null,
            ];
        });

        return [
            'id' => $project->id,
            'project_unique_id' => $project->project_unique_id,
            'name' => $project->name,
            'description' => $project->description,
            'location_id' => $project->location_id,
            'location_name' => optional($project->location)->name,
            'status' => $project->status,
            'status_reason' => $project->status_reason,
            'project_status' => $project->project_status,
            'user_id' => $project->user_id,
            'listed_by' => optional(optional($project->user)->role)->name,
            'purpose_id' => $project->purpose_id,
            'purpose_id_name' => optional($project->purpose)->name,
            'property_id' => $project->property_id,
            'property_id_name' => optional($project->property)->name,
            'property_status_id' => $project->property_status_id,
            'property_status_id_name' => optional($project->propertystatus)->name,
            'property_type_id' => $project->property_type_id,
            'property_type_id_name' => optional($project->propertyType)->name,
            'total_view' => $project->analytics()->count(),
            'custom_field_values' => $formattedCustomFieldValues,
        ];
    }




    // this is for fetch listing assigned projects of agent
    public function fetchAgentTotalAssignedProject(Request $request)
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
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $userId = $userData->id;

            // Fetch projects assigned to the agent by consultancy
            $consultancyProjects = CompanyConsultancyProject::where('agent_id', $userId)
                ->where('type', 'consultancy-agent')
                ->with('consultancy')
                ->get();

            // Extract and transform project data
            $projectsData = $consultancyProjects->map(function ($project) {
                return $this->getProjectDataConsultancy($project->project_id);
            });

            // Return only the projects data
            return response()->json(['data' => $projectsData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

}
