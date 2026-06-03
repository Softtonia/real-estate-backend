<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\Template;

class TemplateController extends Controller
{
    public function index()
    {
        $templates = Template::with(['conditions', 'layout'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Templates fetched successfully.',
            'data' => $templates,
        ]);
    }

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_name' => 'required|string|max:255',
            'status' => 'nullable|in:active,draft',
            'priority' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $slug = $this->generateUniqueSlug($request->template_name);

        $template = Template::create([
            'template_name' => $request->template_name,
            'slug' => $slug,
            'created_by' => auth()->id(),
            'status' => $request->status ?? 'draft',
            'priority' => $request->priority ?? 0,
        ]);

        $template->layout()->create([
            'layout_json' => [
                'sections' => []
            ],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template created successfully.',
            'data' => $template->load(['conditions', 'layout']),
        ], 201);
    }

    public function show($id)
    {
        $template = Template::with(['conditions', 'layout'])->find($id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template fetched successfully.',
            'data' => $template,
        ]);
    }

    public function update(Request $request, $id)
    {
        $template = Template::find($id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'template_name' => 'required|string|max:255',
            'status' => 'nullable|in:active,draft',
            'regenerate_slug' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = [
            'template_name' => $request->template_name,
            'status' => $request->status ?? $template->status,
            'priority' => $request->priority ?? $template->priority,
        ];

        /*
         * By default slug will not change on update.
         * If you want to regenerate slug, send regenerate_slug = true.
         */
        if ($request->boolean('regenerate_slug')) {
            $updateData['slug'] = $this->generateUniqueSlug($request->template_name, $template->id);
        }

        $template->update($updateData);

        return response()->json([
            'status' => true,
            'message' => 'Template updated successfully.',
            'data' => $template->fresh(['conditions', 'layout']),
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $template = Template::find($id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,draft',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $template->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template status updated successfully.',
            'data' => $template,
        ]);
    }
    public function destroy($id)
    {
        $template = Template::find($id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $template->delete();

        return response()->json([
            'status' => true,
            'message' => 'Template deleted successfully.',
        ]);
    }

    private function generateUniqueSlug(string $templateName, $ignoreId = null): string
    {
        $baseSlug = Str::slug($templateName);

        if (!$baseSlug) {
            $baseSlug = 'template';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Template::where('slug', $slug)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
