<?php

namespace App\Http\Controllers\TopFeature;

use App\Http\Controllers\Controller;
use App\Models\Developerlist;
use App\Models\ProjectList;
use App\Models\PropertyList;
use App\Models\TopFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopFeatureController extends Controller
{

    #### ## GET ALL TOP FEATURES ######
    public function index()
    {
        $topFeatures = TopFeature::all();
        return response()->json([
            'message' => 'Top features fetched successfully',
            'status' => 'success',
            'data' => $topFeatures
        ]);
    }










    #### Get Top Features by Project_id, Property_id, or Developer_id ####
    public function getTopFeaturesById(Request $request)
    {
        // Accept exactly one ID
        $projectId = $request->query('project_id');
        $propertyId = $request->query('property_id');
        $developerId = $request->query('developer_id');

        $ids = array_filter([
            'project_id' => $projectId,
            'property_id' => $propertyId,
            'developer_id' => $developerId,
        ]);

        if (count($ids) !== 1) {
            return response()->json([
                'message' => 'Exactly one of project_id, property_id, or developer_id must be provided.',
            ], 422);
        }

        $column = array_key_first($ids);
        $value = $ids[$column];

        // Fetch the model by checking which column was passed
        $model = null;

        switch ($column) {
            case 'project_id':
                $model = ProjectList::find($value);
                break;
            case 'property_id':
                $model = PropertyList::find($value);
                break;
            case 'developer_id':
                $model = Developerlist::find($value);
                break;
        }

        if (!$model) {
            return response()->json([
                'message' => 'Record not found for ' . $column,
            ], 200);
        }

        // Load related top feature via foreign key
        $topFeature = TopFeature::find($model->top_featured_id);

        if (!$topFeature) {
            return response()->json([
                'message' => 'Top feature not assigned to this record.',
            ], 200);
        }

        return response()->json([
            'message' => 'Top feature fetched successfully.',
            'data' => $topFeature
        ], 200);
    }


    #### Create and Update Top Feature ####
    public function createOrUpdateTopFeature(Request $request, $id = null)
    {
        DB::beginTransaction();

        try {
            $allowedFeaturedTypes = [
                'home_page',
                'single_user_details',
                'single_property_details',
                'single_project_details',
                'signle_developer_details',
                'search_project_result',
                'search_property_result',
                'search_developer_result',
                'search_user_detials',
            ];

            $validated = $request->validate([
                'featured_type' => 'required|array',
                'featured_type.*' => 'in:' . implode(',', $allowedFeaturedTypes),
                'status' => 'required|in:1,0',
                'project_id' => 'nullable|integer|exists:project_listings,id',
                'property_id' => 'nullable|integer|exists:properties_listing,id',
                'developer_id' => 'nullable|integer|exists:developer_listings,id',
            ]);

            // Check that only one ID is passed
            $assignments = array_filter([
                'project_id' => $request->project_id,
                'property_id' => $request->property_id,
                'developer_id' => $request->developer_id,
            ]);

            if (count($assignments) > 1) {
                return response()->json([
                    'message' => 'Only one of project_id, property_id, or developer_id is allowed.',
                    'errors' => [
                        'assignment' => ['Only one of project_id, property_id, or developer_id is allowed.']
                    ]
                ], 422);
            }

            // CREATE or UPDATE
            if ($id) {
                $topFeature = TopFeature::find($id);
                if (!$topFeature) {
                    return response()->json([
                        'message' => 'Top feature not found.'
                    ], 200);
                }

                $topFeature->update([
                    'featured_type' => $request->featured_type,
                    'status' => $request->status,
                ]);
            } else {
                $topFeature = TopFeature::create([
                    'featured_type' => $request->featured_type,
                    'status' => $request->status,
                ]);
            }

            // Re-assign top_featured_id in related table
            if (isset($assignments['project_id'])) {
                ProjectList::where('top_featured_id', $topFeature->id)->update(['top_featured_id' => null]);
                ProjectList::where('id', $assignments['project_id'])->update(['top_featured_id' => $topFeature->id]);
            } elseif (isset($assignments['property_id'])) {
                PropertyList::where('top_featured_id', $topFeature->id)->update(['top_featured_id' => null]);
                PropertyList::where('id', $assignments['property_id'])->update(['top_featured_id' => $topFeature->id]);
            } elseif (isset($assignments['developer_id'])) {
                Developerlist::where('top_featured_id', $topFeature->id)->update(['top_featured_id' => null]);
                Developerlist::where('id', $assignments['developer_id'])->update(['top_featured_id' => $topFeature->id]);
            }

            DB::commit();

            return response()->json([
                'message' => $id ? 'Top feature updated successfully.' : 'Top feature created successfully.',
                'data' => $topFeature
            ], $id ? 200 : 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to ' . ($id ? 'update' : 'create') . ' top feature',
                'error' => $e->getMessage()
            ], 500);
        }
    }



}
