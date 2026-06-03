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
            'conditions.*.condition_type' => 'required|in:all,purpose,property,property-type,property-status,project-status,developer',
            'conditions.*.condition_value' => 'nullable|string|max:255',
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
                'condition_type' => $conditionData['condition_type'],
                'condition_value' => $conditionData['condition_value'] ?? null,
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
            'conditions.*.condition_type' => 'required|in:all,purpose,property,property-type,property-status,project-status,developer',
            'conditions.*.condition_value' => 'nullable|string|max:255',
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
                    'condition_type' => $conditionData['condition_type'],
                    'condition_value' => $conditionData['condition_value'] ?? null,
                ]);

                $savedConditions[] = $condition;
            } else {
                $savedConditions[] = TemplateDisplayCondition::create([
                    'template_id' => $request->template_id,
                    'show_type' => $conditionData['show_type'],
                    'post_type' => $conditionData['post_type'],
                    'condition_type' => $conditionData['condition_type'],
                    'condition_value' => $conditionData['condition_value'] ?? null,
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