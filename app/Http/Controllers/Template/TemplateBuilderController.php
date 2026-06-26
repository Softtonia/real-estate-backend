<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Template;
use App\Models\TemplateComponent;

class TemplateBuilderController extends Controller
{
    public function show($template_id)
    {
        $template = Template::with(['layout', 'conditions', 'postType'])->find($template_id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $components = TemplateComponent::where('status', true)->get();

        return response()->json([
            'status' => true,
            'message' => 'Template builder data fetched successfully.',
            'data' => [
                'template' => $template,
                'components' => $components,
                'layout_json' => $template->layout?->layout_json ?? [
                    'sections' => []
                ],
            ],
        ]);
    }

    public function save(Request $request, $template_id)
    {
        $template = Template::find($template_id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'layout_json' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $layoutJson = $request->layout_json;

        if (is_string($layoutJson)) {
            $decoded = json_decode($layoutJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid layout JSON.',
                ], 422);
            }

            $layoutJson = $decoded;
        }

        $template->layout()->updateOrCreate(
            ['template_id' => $template->id],
            ['layout_json' => $layoutJson]
        );

        return response()->json([
            'status' => true,
            'message' => 'Template layout saved successfully.',
            'data' => [
                'template_id' => $template->id,
                'layout_json' => $layoutJson,
            ],
        ]);
    }
}