<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Project; // Adjust this according to your project structure
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\Amenity;
use App\Models\Status;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Location;
use App\Models\Property;
use App\Models\Builder;
use Log;
class ProjectController extends Controller
{
    public function index()
    {
        // Load projects with the propertyType, location, and amenity relationships
        $projects = Project::with(['propertyType', 'location', 'propertiesname', 'status', 'builder'])->get();

        // Transform the collection to attach the property type name, location name, and amenity data
        $projects->transform(function ($project) {
            // Check if propertyType relationship is loaded
            if ($project->propertyType) {
                $project->property_type_name = $project->propertyType->name;
            } else {
                $project->property_type_name = null; // or set it to a default value
            }

            // Check if location relationship is loaded
            if ($project->status) {
                $project->property_status = $project->status->name;
            } else {
                $project->property_status = null; // or set it to a default value
            }

            // Check if location relationship is loaded
            if ($project->location) {
                $project->location_name = $project->location->name;
            } else {
                $project->location_name = null; // or set it to a default value
            }


            // Check if property relationship is loaded
            if ($project->propertiesname) {
                $project->properties_name = $project->propertiesname->name;
            } else {
                $project->properties_name = null; // or set it to a default value
            }

            // Fetch amenities data based on IDs stored in the 'amenities' column
            $amenityIds = explode(',', $project->amenities);

            $amenities = Amenity::whereIn('id', $amenityIds)->get();

            // Attach the fetched amenity data to the project object
            $project->amenities_data = $amenities;


            // Fetch builders data based on IDs stored in the 'developers' column
            $developerIds = explode(',', $project->developers);
            $developers = Builder::whereIn('id', $developerIds)->get();

            // Attach the fetched builder data to the project object
            $project->developers_data = $developers;


            // unset($project->propertyType, $project->location); // Remove unnecessary attributes
            return $project;
        });

        return $projects;
    }


    public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'project_name' => 'required|unique:projects',
        'project_description' => 'nullable',
        'developer' => 'nullable|max:100',
        'property_status' => 'nullable|exists:status,id',
        'property_type_id' => 'nullable|exists:property_types,id',
        'amenities' => 'nullable|array',
        'amenities.*' => 'nullable',
        'price_ranges' => 'nullable|max:100',
        'floor_plans' => 'nullable',
        'floor_plan_area' => 'nullable',
        'completion_dates' => 'nullable|string',
        'images' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'virtual_tour' => 'nullable|string',
        'contact_information' => 'required|regex:/^[0-9]{1,10}$/',
        'properties_id' => 'nullable|exists:properties,id',
        'floor_area' => 'nullable',
        'floor_plan_media' => 'nullable',
        'specification' => 'nullable',
        'gallery' => 'nullable',
        'video' => 'nullable',
        'video_url' => 'nullable',
        'overview' => 'nullable',
        'price_list' => 'nullable',
        'brochure' => 'nullable',
        'rera' => 'required',
        'min_area' => 'nullable',
        'max_area' => 'nullable|numeric|gte:min_area',
        'area_unit' => 'nullable',
        'min_cost' => 'nullable|numeric',
        'max_cost' => 'nullable|numeric|gte:min_cost',
        'min_cost_unit' => 'nullable',
        'max_cost_unit' => 'nullable',
        'projects_unique_id' => 'nullable|unique:projects,projects_unique_id',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $uploadDirectory = [
        'images' => 'project_images',
        'gallery' => 'project_galleries',
        'video' => 'project_videos',
        'brochure' => 'project_brochures',
    ];

    $uploadedFiles = [];

    foreach ($uploadDirectory as $inputName => $folder) {
        if ($request->hasFile($inputName)) {
            $files = $request->file($inputName);
            if (is_array($files)) {
                foreach ($files as $file) {
                    $uploadedFiles[$inputName][] = $this->handleFileUpload($file, $folder);
                }
            } else {
                $uploadedFiles[$inputName] = $this->handleFileUpload($files, $folder);
            }
        }
    }

    $builders = Builder::pluck('id')->toArray();
    $buildersString = implode(',', $builders);

    $lastProject = Project::latest()->first();
    $lastId = $lastProject ? intval(substr($lastProject->projects_unique_id, 4)) : 0;
    $projectUniqueId = 'PROJ' . str_pad($lastId + 1, 3, '0', STR_PAD_LEFT);

    $projectData = [
        'project_name' => $request->input('project_name'),
        'project_description' => $request->input('project_description'),
        'property_status' => $request->input('property_status'),
        'property_type_id' => $request->input('property_type_id'),
        'amenities' => json_encode($request->input('amenities', [])),
        'floor_plans' => $request->input('floor_plans'),
        'floor_plan_area' => $request->input('floor_plan_area'),
        'completion_dates' => $request->input('completion_dates'),
        'images' => $uploadedFiles['images'] ?? null,
        'floor_plan_media' => $uploadedFiles['floor_plan_media'] ?? null,
        'virtual_tour' => $request->input('virtual_tour'),
        'contact_information' => $request->input('contact_information'),
        'specification' => $request->input('specification'),
        'properties_id' => $request->input('properties_id'),
        'video' => $uploadedFiles['video'] ?? null,
        'video_url' => $request->input('video_url'),
        'overview' => $request->input('overview'),
        'brochure' => $uploadedFiles['brochure'] ?? null,
        'floor_area' => $request->input('floor_area'),
        'price_list' => $request->input('price_list'),
        'rera' => $request->input('rera'),
        'gallery' => $uploadedFiles['gallery'] ?? null,
        'min_area' => $request->input('min_area'),
        'max_area' => $request->input('max_area'),
        'area_unit' => $request->input('area_unit'),
        'min_cost' => $request->input('min_cost'),
        'max_cost' => $request->input('max_cost'),
        'min_cost_unit' => $request->input('min_cost_unit'),
        'max_cost_unit' => $request->input('max_cost_unit'),
        'projects_unique_id' => $projectUniqueId,
        'developers' => $buildersString
    ];

    $project = Project::create($projectData);

    return response()->json(['message' => 'Project created successfully', 'project' => $project], 201);
}

private function handleFileUpload($file, $folder)
{
    $name = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
    $file->move(public_path('uploads/' . $folder), $name);
    return url('uploads/' . $folder . '/' . $name);
}

public function update(Request $request)
{
    try {
        $id = $request->id;
        $project = Project::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'project_name' => 'nullable',
            'project_description' => 'nullable',
            'location_id' => 'nullable|exists:locations,id',
            'property_status' => 'nullable|exists:status,id',
            'property_type_id' => 'nullable|exists:property_types,id',
            'amenities' => 'nullable|array',
            'amenities.*' => 'nullable',
            'price_ranges' => 'nullable|max:100',
            'floor_plans' => 'nullable',
            'floor_plan_area' => 'nullable',
            'completion_dates' => 'nullable|string',
            'images' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'virtual_tour' => 'nullable|string',
            'contact_information' => 'nullable|regex:/^[0-9]{1,10}$/',
            'properties_id' => 'nullable|exists:properties,id',
            'floor_area' => 'nullable',
            'floor_plan_media' => 'nullable|file',
            'specification' => 'nullable',
            'gallery' => 'nullable|file',
            'video' => 'nullable|file',
            'video_url' => 'nullable',
            'overview' => 'nullable',
            'price_list' => 'nullable',
            'brochure' => 'nullable|file',
            'rera' => 'nullable',
            'min_area' => 'nullable',
            'max_area' => 'nullable|numeric|gte:min_area',
            'area_unit' => 'nullable',
            'min_cost' => 'nullable|numeric',
            'max_cost' => 'nullable|numeric|gte:min_cost',
            'min_cost_unit' => 'nullable',
            'max_cost_unit' => 'nullable',
            'developers' => 'nullable|array',
            'developers.*' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Handle file uploads
        $imagePath = $request->hasFile('images')
            ? $this->handleFileUpload($request->file('images'), 'project_images')
            : $project->images;

        $galleryPath = $request->hasFile('gallery')
            ? $this->handleFileUpload($request->file('gallery'), 'project_galleries')
            : $project->gallery;

        $floorPlanMediaPath = $request->hasFile('floor_plan_media')
            ? $this->handleFileUpload($request->file('floor_plan_media'), 'project_floor_plan_media')
            : $project->floor_plan_media;

        $brochurePath = $request->hasFile('brochure')
            ? $this->handleFileUpload($request->file('brochure'), 'project_brochures')
            : $project->brochure;

        $videoPath = $request->hasFile('video')
            ? $this->handleFileUpload($request->file('video'), 'project_videos')
            : $project->video;

        // Fetch amenities and developers as a string
        $amenitiesString = $request->has('amenities') ? implode(',', $request->input('amenities')) : null;
        $developersString = $request->has('developers') ? implode(',', $request->input('developers')) : null;

        // Update project data
        // $projectData = $request->all();
        // $projectData['images'] = $imagePath;
        // $projectData['gallery'] = $galleryPath;
        // $projectData['floor_plan_media'] = $floorPlanMediaPath;
        // $projectData['brochure'] = $brochurePath;
        // $projectData['video'] = $videoPath;
        // $projectData['amenities'] = $amenitiesString;
        // $projectData['developers'] = $developersString; // Handle developers if needed

        // $project->update($projectData);

        // Return response
        return response()->json([
            'message' => 'Project updated successfully',
            'data' => $project->fresh()
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Project not found'], 404);
    } catch (\Exception $e) {
        \Log::error('Error occurred while processing the request: ' . $e->getMessage());
        return response()->json(['error' => 'An error occurred while processing your request.'], 500);
    }
}




    public function show(Request $request)
    {
        try {
            $id = $request->id;
            // Load the specific project by ID with the propertyType, location, and amenity relationships
            $project = Project::with(['propertyType', 'location', 'propertiesname', 'status', 'builder'])->find($id);

            if (!$project) {
                // If the project with the given ID is not found, return an appropriate response
                return response()->json(['error' => 'Project not found'], 404);
            }

            // Transform the project object to attach the property type name, location name, and amenity data
            $projectData = $project->toArray();

            // Check if propertyType relationship is loaded
            if ($project->propertyType) {
                $projectData['property_type_name'] = $project->propertyType->name;
            } else {
                $projectData['property_type_name'] = null; // or set it to a default value
            }

            // Check if status relationship is loaded
            if ($project->status) {
                $projectData['property_status'] = $project->status->name;
            } else {
                $projectData['property_status'] = null; // or set it to a default value
            }

            // Check if location relationship is loaded
            if ($project->location) {
                $projectData['location_name'] = $project->location->name;
            } else {
                $projectData['location_name'] = null; // or set it to a default value
            }

            // Check if propertiesname relationship is loaded
            if ($project->propertiesname) {
                $projectData['properties_name'] = $project->propertiesname->name;
            } else {
                $projectData['properties_name'] = null; // or set it to a default value
            }

            // Fetch amenities data based on IDs stored in the 'amenities' column
            $amenityIds = explode(',', $project->amenities);
            $amenities = Amenity::whereIn('id', $amenityIds)->get();

            // Attach the fetched amenity data to the project object
            $projectData['amenities_data'] = $amenities;

            // Fetch builders data based on IDs stored in the 'developers' column
            $developerIds = explode(',', $project->developers);
            $developers = Builder::whereIn('id', $developerIds)->get();

            // Attach the fetched builder data to the project object
            $projectData['developers_data'] = $developers;

            return response()->json($projectData);
        } catch (\Exception $e) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function destroy(Request $request)
    {
        $id = $request->id;
        $project = Project::find($id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }
        $project->delete();
        return response()->json(['success' => 'Project deleted successfully']);
    }
}
