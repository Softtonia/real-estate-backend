<?php

namespace App\Http\Controllers\TopFeature;

use App\Http\Controllers\Controller;
use App\Models\TopFeature;
use Illuminate\Http\Request;

class TopFeatureController extends Controller
{
    //
    public function getTopFeaturesByProjectId($id)
    {
        $features = TopFeature::where('project_id', $id)->select('id', 'project_id', 'section_name', 'status', 'created_at', 'updated_at')->get();

        $features = $features->map(function ($item) {
            $item->section_name = $this->decodeSectionName($item->section_name);
            return $item;
        });

        return response()->json($features);
    }


    public function getTopFeaturesByPropertyId($id)
    {
        $features = TopFeature::where('property_id', $id)->select('id', 'property_id', 'section_name', 'status', 'created_at', 'updated_at')->get();

        $features = $features->map(function ($item) {
            $item->section_name = $this->decodeSectionName($item->section_name);
            return $item;
        });

        return response()->json($features);
    }

    public function getTopFeaturesByDeveloperId($id)
    {
        $features = TopFeature::where('developer_id', $id)->select('id', 'developer_id', 'section_name', 'status', 'created_at', 'updated_at')->get();

        $features = $features->map(function ($item) {
            $item->section_name = $this->decodeSectionName($item->section_name);
            return $item;
        });

        return response()->json($features);
    }


    public function getTopFeaturesByAgentId($id)
    {
        $features = TopFeature::where('agent_id', $id)->select('id', 'agent_id', 'section_name', 'status', 'created_at', 'updated_at')->get();

        $features = $features->map(function ($item) {
            $item->section_name = $this->decodeSectionName($item->section_name);
            return $item;
        });

        return response()->json($features);
    }

    private function decodeSectionName($sectionName)
    {
        // If already an array, return as-is
        if (is_array($sectionName))
            return $sectionName;

        // Try to decode JSON (safe way)
        $decoded = json_decode($sectionName, true);

        // If decoding failed (e.g., stored like [item1,item2] without quotes), clean it
        if (is_null($decoded)) {
            // Remove square brackets and spaces, split by comma
            $sectionName = str_replace(['[', ']', ' '], '', $sectionName);
            $decoded = explode(',', $sectionName);
        }

        return $decoded;
    }


    public function updateTopFeatures(Request $request)
    {
        try {
            // Normalize section_name to array
            $section_name = $request->section_name;
            if (!is_array($section_name)) {
                $section_name = [$section_name];
            }

            // Identify ID type
            $project_id = $request->project_id ?? null;
            $property_id = $request->property_id ?? null;
            $developer_id = $request->developer_id ?? null;
            $agent_id = $request->agent_id ?? null;

            // Ensure exactly one ID is passed
            $ids = array_filter([
                'project_id' => $project_id,
                'property_id' => $property_id,
                'developer_id' => $developer_id,
                'agent_id' => $agent_id,
            ]);

            if (count($ids) !== 1) {
                return response()->json([
                    'error' => 'Exactly one of project_id, property_id, developer_id, or agent_id is required.'
                ], 422);
            }

            $column = array_key_first($ids);
            $value = $ids[$column];

            // Update if exists, otherwise create
            $topFeature = TopFeature::where($column, $value)->first();

            if ($topFeature) {
                $topFeature->update([
                    'section_name' => $section_name,
                    'project_id' => $project_id,
                    'property_id' => $property_id,
                    'developer_id' => $developer_id,
                    'agent_id' => $agent_id,
                ]);
            } else {
                TopFeature::create([
                    'section_name' => $section_name,
                    'project_id' => $project_id,
                    'property_id' => $property_id,
                    'developer_id' => $developer_id,
                    'agent_id' => $agent_id,
                ]);
            }

            return response()->json(['message' => 'Top feature updated successfully.'], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update. ' . $e->getMessage()], 500);
        }
    }



}
