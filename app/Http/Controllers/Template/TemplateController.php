<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = Template::with(['conditions', 'layout'])
            ->latest();

        if ($request->filled('template_type')) {
            $query->where('template_type', $request->template_type);
        }

        if ($request->filled('post_type_id')) {
            $query->where('post_type_id', $request->post_type_id);
        }

        if ($request->filled('post_type_slug')) {
            $query->where('post_type_slug', $request->post_type_slug);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('template_name', 'like', '%' . $request->search . '%')
                    ->orWhere('slug', 'like', '%' . $request->search . '%')
                    ->orWhere('shortcode', 'like', '%' . $request->search . '%')
                    ->orWhere('post_type_slug', 'like', '%' . $request->search . '%');
            });
        }

        $templates = $query->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'status' => true,
            'message' => 'Templates fetched successfully.',
            'data' => $templates,
        ]);
    }

    public function create(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_type' => 'required|in:single_post,page,section',
            'template_name' => 'required|string|max:255',

            // Only required when template_type = single_post
            'post_type_id' => 'required_if:template_type,single_post|nullable|integer|exists:post_types,id',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $postType = null;

            if ($payload['template_type'] === 'single_post') {
                $postType = PostType::where('status', true)
                    ->where('id', $payload['post_type_id'])
                    ->first();

                if (!$postType) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Selected post type is inactive or not found.',
                    ], 422);
                }
            }

            $slug = $this->generateUniqueSlug($payload['template_name']);

            $template = Template::create([
                'template_type' => $payload['template_type'],
                'post_type_id' => $postType?->id,
                'post_type_slug' => $postType?->slug,
                'template_name' => $payload['template_name'],
                'slug' => $slug,
                'created_by' => $this->getAuthenticatedUserId(),
                'status' => 'draft',
                'priority' => 0,
            ]);

            $template->update([
                'shortcode' => $this->generateShortcode($template->id),
            ]);

            $template->layout()->create([
                'layout_json' => [
                    'sections' => [],
                ],
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Template created successfully.',
                'data' => $template->fresh(['conditions', 'layout']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to create template.',
                'error' => $e->getMessage(),
            ], 500);
        }
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

        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_type' => 'required|in:single_post,page,section',
            'template_name' => 'required|string|max:255',
            'post_type_id' => 'required_if:template_type,single_post|nullable|integer|exists:post_types,id',
            'status' => 'nullable|in:active,draft',
            'regenerate_slug' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $postType = null;

            if ($payload['template_type'] === 'single_post') {
                $postType = PostType::where('status', true)
                    ->where('id', $payload['post_type_id'])
                    ->first();

                if (!$postType) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Selected post type is inactive or not found.',
                    ], 422);
                }
            }

            $updateData = [
                'template_type' => $payload['template_type'],
                'template_name' => $payload['template_name'],
                'post_type_id' => $postType?->id,
                'post_type_slug' => $postType?->slug,
                'status' => $payload['status'] ?? $template->status,
                'priority' => $payload['priority'] ?? $template->priority,
            ];

            if (!empty($payload['regenerate_slug'])) {
                $updateData['slug'] = $this->generateUniqueSlug(
                    $payload['template_name'],
                    $template->id
                );
            }

            if (empty($template->shortcode)) {
                $updateData['shortcode'] = $this->generateShortcode($template->id);
            }

            $template->update($updateData);

            if (!$template->layout) {
                $template->layout()->create([
                    'layout_json' => [
                        'sections' => [],
                    ],
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Template updated successfully.',
                'data' => $template->fresh(['conditions', 'layout']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to update template.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
            return $this->validationErrorResponse($validator);
        }

        $template->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template status updated successfully.',
            'data' => $template->fresh(['conditions', 'layout']),
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

        DB::beginTransaction();

        try {
            $template->conditions()->delete();

            if ($template->layout) {
                $template->layout()->delete();
            }

            $template->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Template deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to delete template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function options()
    {
        return response()->json([
            'status' => true,
            'message' => 'Template options fetched successfully.',
            'data' => [
                'template_types' => [
                    [
                        'label' => 'Single Post',
                        'value' => 'single_post',
                    ],
                    [
                        'label' => 'Page',
                        'value' => 'page',
                    ],
                    [
                        'label' => 'Section',
                        'value' => 'section',
                    ],
                ],
                'post_types' => $this->getPostTypesForDropdown(),
            ],
        ]);
    }

    public function shortcodes()
    {
        $templates = Template::query()
            ->select([
                'id',
                'template_type',
                'post_type_id',
                'post_type_slug',
                'template_name',
                'slug',
                'shortcode',
                'status',
                'priority',
            ])
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Template shortcodes fetched successfully.',
            'data' => $templates,
        ]);
    }

    private function getPostTypesForDropdown(): array
    {
        return PostType::query()
            ->where('status', true)
            ->orderBy('name')
            ->get()
            ->map(function ($postType) {
                return [
                    'id' => $postType->id,
                    'label' => $postType->name,
                    'value' => $postType->id,
                    'slug' => $postType->slug,
                ];
            })
            ->values()
            ->toArray();
    }

    private function generateShortcode($templateId): string
    {
        return '[template id="' . $templateId . '"]';
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

    private function getPayload(Request $request): array
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            $payload = $request->all();
        }

        if (empty($payload) && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return is_array($payload) ? $payload : [];
    }

    private function getAuthenticatedUserId()
    {
        if (auth()->id()) {
            return auth()->id();
        }

        if (request()->user()) {
            return request()->user()->id;
        }

        return null;
    }

    private function validationErrorResponse($validator)
    {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }
}