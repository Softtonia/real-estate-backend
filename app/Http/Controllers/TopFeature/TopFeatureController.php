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
        $features = TopFeature::where('project_id', $id)->select('id','project_id','section_name','status','created_at','updated_at')->get();

        $features = $features->map(function ($item) {
            $item->section_name = $this->decodeSectionName($item->section_name);
            return $item;
        });

        return response()->json($features);
    }


    public function getTopFeaturesByPropertyId($id)
    {
        $features = TopFeature::where('property_id', $id)->select('id','property_id','section_name','status','created_at','updated_at')->get();

        $features = $features->map(function ($item) {
            $item->section_name = $this->decodeSectionName($item->section_name);
            return $item;
        });

        return response()->json($features);
    }

    public function getTopFeaturesByDeveloperId($id)
    {
        $features = TopFeature::where('developer_id', $id)->select('id','developer_id','section_name','status','created_at','updated_at')->get();

        $features = $features->map(function ($item) {
            $item->section_name = $this->decodeSectionName($item->section_name);
            return $item;
        });

        return response()->json($features);
    }


    public function getTopFeaturesByAgentId($id)
    {
        $features = TopFeature::where('agent_id', $id)->select('id','agent_id','section_name','status','created_at','updated_at')->get();

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

}
