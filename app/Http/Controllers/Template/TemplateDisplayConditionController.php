<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Template;
use App\Models\TemplateDisplayCondition;

class TemplateDisplayConditionController extends Controller
{
    public function index($template_id)
    {
        $template = Template::with('conditions')->find($template_id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template conditions fetched successfully.',
            'data' => $template->conditions,
        ]);
    }

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|exists:templates,id',
            'conditions' => 'required|array|min:1',

            'conditions.*.show_type' => 'required|in:include,exclude',
            'conditions.*.post_type' => 'required|in:property-listing,project-listing,developer-listing',

            'conditions.*.modelFields' => 'required|array|min:1',
            'conditions.*.modelFields.*.model' => 'required|string|in:purpose,property,property_type,property_status,project_status,developer',
            'conditions.*.modelFields.*.condition' => 'required|array|min:1',
            'conditions.*.modelFields.*.condition.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $createdConditions = [];

        foreach ($request->conditions as $conditionData) {
            $createdConditions[] = TemplateDisplayCondition::create([
                'template_id' => $request->template_id,
                'show_type' => $conditionData['show_type'],
                'post_type' => $conditionData['post_type'],

                // main model field info
                'condition_type' => 'model_fields',

                // multiple model and condition values store as JSON
                'condition_value' => $conditionData['modelFields'],
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template conditions created successfully.',
            'data' => $createdConditions,
        ], 201);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|exists:templates,id',
            'conditions' => 'required|array|min:1',

            'conditions.*.id' => 'nullable|exists:template_display_conditions,id',
            'conditions.*.show_type' => 'required|in:include,exclude',
            'conditions.*.post_type' => 'required|in:property-listing,project-listing,developer-listing',

            'conditions.*.modelFields' => 'required|array|min:1',
            'conditions.*.modelFields.*.model' => 'required|string|in:purpose,property,property_type,property_status,project_status,developer',
            'conditions.*.modelFields.*.condition' => 'required|array|min:1',
            'conditions.*.modelFields.*.condition.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $savedConditions = [];

        foreach ($request->conditions as $conditionData) {
            if (!empty($conditionData['id'])) {
                $condition = TemplateDisplayCondition::where('id', $conditionData['id'])
                    ->where('template_id', $request->template_id)
                    ->first();

                if (!$condition) {
                    continue;
                }

                $condition->update([
                    'show_type' => $conditionData['show_type'],
                    'post_type' => $conditionData['post_type'],
                    'condition_type' => 'model_fields',
                    'condition_value' => $conditionData['modelFields'],
                ]);

                $savedConditions[] = $condition;
            } else {
                $savedConditions[] = TemplateDisplayCondition::create([
                    'template_id' => $request->template_id,
                    'show_type' => $conditionData['show_type'],
                    'post_type' => $conditionData['post_type'],
                    'condition_type' => 'model_fields',
                    'condition_value' => $conditionData['modelFields'],
                ]);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Template conditions updated successfully.',
            'data' => $savedConditions,
        ]);
    }

    public function destroy($id)
    {
        $condition = TemplateDisplayCondition::find($id);

        if (!$condition) {
            return response()->json([
                'status' => false,
                'message' => 'Template condition not found.',
            ], 404);
        }

        $condition->delete();

        return response()->json([
            'status' => true,
            'message' => 'Template condition deleted successfully.',
        ]);
    }
}
