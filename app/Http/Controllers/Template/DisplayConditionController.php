<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\DisplayCondition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DisplayConditionController extends Controller
{
    public function index(Request $request)
    {
        $query = DisplayCondition::with('template');

        if ($request->filled('template_id')) {
            $query->where('template_id', $request->template_id);
        }

        if ($request->filled('post_type')) {
            $query->where('post_type', $request->post_type);
        }

        if ($request->filled('condition_type')) {
            $query->where('condition_type', $request->condition_type);
        }

        $displayConditions = $query->latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Display conditions fetched successfully.',
            'data' => $displayConditions,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'template_id' => [
                'required',
                'exists:templates,id',
            ],

            'conditions' => [
                'required',
                'array',
                'min:1',
            ],

            'conditions.*.show_type' => [
                'required',
                Rule::in(['include', 'exclude']),
            ],

            'conditions.*.post_type' => [
                'required',
                Rule::in([
                    'property-listing',
                    'project-listing',
                    'developer-listing',
                ]),
            ],

            'conditions.*.condition_type' => [
                'required',
                Rule::in([
                    'all',
                    'purpose',
                    'property',
                    'property-type',
                    'property-status',
                ]),
            ],

            'conditions.*.value' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $createdConditions = [];

        foreach ($request->conditions as $condition) {
            if ($condition['condition_type'] !== 'all' && empty($condition['value'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Value is required when condition type is not all.',
                ], 422);
            }

            $createdConditions[] = DisplayCondition::create([
                'template_id' => $request->template_id,
                'show_type' => $condition['show_type'],
                'post_type' => $condition['post_type'],
                'condition_type' => $condition['condition_type'],
                'value' => $condition['condition_type'] === 'all'
                    ? null
                    : $condition['value'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Display conditions created successfully.',
            'data' => $createdConditions,
        ], 201);
    }

    public function show($id)
    {
        $displayCondition = DisplayCondition::with('template')->find($id);

        if (!$displayCondition) {
            return response()->json([
                'success' => false,
                'message' => 'Display condition not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Display condition fetched successfully.',
            'data' => $displayCondition,
        ]);
    }

    public function update(Request $request, $id)
    {
        $displayCondition = DisplayCondition::find($id);

        if (!$displayCondition) {
            return response()->json([
                'success' => false,
                'message' => 'Display condition not found.',
            ], 404);
        }

        $request->validate([
            'template_id' => [
                'required',
                'exists:templates,id',
            ],

            'show_type' => [
                'required',
                Rule::in(['include', 'exclude']),
            ],

            'post_type' => [
                'required',
                Rule::in([
                    'property-listing',
                    'project-listing',
                    'developer-listing',
                ]),
            ],

            'condition_type' => [
                'required',
                Rule::in([
                    'all',
                    'purpose',
                    'property',
                    'property-type',
                    'property-status',
                ]),
            ],

            'value' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        if ($request->condition_type !== 'all' && empty($request->value)) {
            return response()->json([
                'success' => false,
                'message' => 'Value is required when condition type is not all.',
            ], 422);
        }

        $displayCondition->update([
            'template_id' => $request->template_id,
            'show_type' => $request->show_type,
            'post_type' => $request->post_type,
            'condition_type' => $request->condition_type,
            'value' => $request->condition_type === 'all'
                ? null
                : $request->value,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Display condition updated successfully.',
            'data' => $displayCondition,
        ]);
    }

    public function destroy($id)
    {
        $displayCondition = DisplayCondition::find($id);

        if (!$displayCondition) {
            return response()->json([
                'success' => false,
                'message' => 'Display condition not found.',
            ], 404);
        }

        $displayCondition->delete();

        return response()->json([
            'success' => true,
            'message' => 'Display condition deleted successfully.',
        ]);
    }
}