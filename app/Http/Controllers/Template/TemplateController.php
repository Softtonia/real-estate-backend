<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\TemplateDisplayCondition;
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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('template_name', 'like', '%' . $request->search . '%')
                    ->orWhere('slug', 'like', '%' . $request->search . '%')
                    ->orWhere('shortcode', 'like', '%' . $request->search . '%');
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
            'status' => 'nullable|in:active,draft',
            'priority' => 'nullable|integer|min:0',

            'conditions' => 'nullable|array',
            'conditions.*.show_type' => 'required_with:conditions|in:include,exclude',
            'conditions.*.source_type' => 'required_with:conditions|in:post_type,taxonomy',
            'conditions.*.post_type_slug' => 'nullable|string|max:255',
            'conditions.*.taxonomy_slug' => 'nullable|string|max:255',
            'conditions.*.taxonomy_term_ids' => 'nullable|array',
            'conditions.*.taxonomy_term_ids.*' => 'integer',
            'conditions.*.relation' => 'nullable|in:and,or',
        ]);

        $validator->after(function ($validator) use ($payload) {
            $this->validateConditions($validator, $payload['conditions'] ?? []);
        });

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $slug = $this->generateUniqueSlug($payload['template_name']);

            $template = Template::create([
                'template_type' => $payload['template_type'],
                'template_name' => $payload['template_name'],
                'slug' => $slug,
                'created_by' => $this->getAuthenticatedUserId(),
                'status' => $payload['status'] ?? 'draft',
                'priority' => $payload['priority'] ?? 0,
            ]);

            $template->update([
                'shortcode' => $this->generateShortcode($template->id),
            ]);

            $template->layout()->create([
                'layout_json' => [
                    'sections' => [],
                ],
            ]);

            $this->syncConditions($template, $payload['conditions'] ?? []);

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
            'status' => 'nullable|in:active,draft',
            'regenerate_slug' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',

            'conditions' => 'nullable|array',
            'conditions.*.show_type' => 'required_with:conditions|in:include,exclude',
            'conditions.*.source_type' => 'required_with:conditions|in:post_type,taxonomy',
            'conditions.*.post_type_slug' => 'nullable|string|max:255',
            'conditions.*.taxonomy_slug' => 'nullable|string|max:255',
            'conditions.*.taxonomy_term_ids' => 'nullable|array',
            'conditions.*.taxonomy_term_ids.*' => 'integer',
            'conditions.*.relation' => 'nullable|in:and,or',
        ]);

        $validator->after(function ($validator) use ($payload) {
            $this->validateConditions($validator, $payload['conditions'] ?? []);
        });

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $updateData = [
                'template_type' => $payload['template_type'],
                'template_name' => $payload['template_name'],
                'status' => $payload['status'] ?? $template->status,
                'priority' => $payload['priority'] ?? $template->priority,
                'shortcode' => $template->shortcode ?: $this->generateShortcode($template->id),
            ];

            if (!empty($payload['regenerate_slug'])) {
                $updateData['slug'] = $this->generateUniqueSlug(
                    $payload['template_name'],
                    $template->id
                );
            }

            $template->update($updateData);

            if (!$template->layout) {
                $template->layout()->create([
                    'layout_json' => [
                        'sections' => [],
                    ],
                ]);
            }

            if (array_key_exists('conditions', $payload)) {
                $this->syncConditions($template, $payload['conditions'] ?? []);
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
                'show_types' => [
                    [
                        'label' => 'Include',
                        'value' => 'include',
                    ],
                    [
                        'label' => 'Exclude',
                        'value' => 'exclude',
                    ],
                ],
                'source_types' => [
                    [
                        'label' => 'Post Type',
                        'value' => 'post_type',
                    ],
                    [
                        'label' => 'Taxonomy',
                        'value' => 'taxonomy',
                    ],
                ],
                'relations' => [
                    [
                        'label' => 'AND',
                        'value' => 'and',
                    ],
                    [
                        'label' => 'OR',
                        'value' => 'or',
                    ],
                ],
                'post_types' => $this->getPostTypesForDropdown(),
                'taxonomies' => $this->getTaxonomiesForDropdown(),
            ],
        ]);
    }

    public function shortcodes()
    {
        $templates = Template::query()
            ->select([
                'id',
                'template_type',
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

    private function syncConditions(Template $template, array $conditions): void
    {
        $template->conditions()->delete();

        foreach ($conditions as $condition) {
            TemplateDisplayCondition::create([
                'template_id' => $template->id,
                'show_type' => $condition['show_type'],
                'source_type' => $condition['source_type'],
                'post_type_slug' => $condition['post_type_slug'] ?? null,
                'taxonomy_slug' => $condition['source_type'] === 'taxonomy'
                    ? ($condition['taxonomy_slug'] ?? null)
                    : null,
                'taxonomy_term_ids' => $condition['source_type'] === 'taxonomy'
                    ? ($condition['taxonomy_term_ids'] ?? [])
                    : [],
                'relation' => $condition['relation'] ?? 'and',

                /*
                 * Keep old columns filled also if your old resolver/DB still uses them.
                 */
                'post_type' => $condition['post_type_slug'] ?? null,
                'condition_type' => $condition['source_type'],
                'condition_value' => $condition['source_type'] === 'taxonomy'
                    ? [
                        'taxonomy_slug' => $condition['taxonomy_slug'] ?? null,
                        'taxonomy_term_ids' => $condition['taxonomy_term_ids'] ?? [],
                    ]
                    : ($condition['post_type_slug'] ?? null),
            ]);
        }
    }

    private function validateConditions($validator, array $conditions): void
    {
        foreach ($conditions as $index => $condition) {
            $sourceType = $condition['source_type'] ?? null;

            if ($sourceType === 'post_type' && empty($condition['post_type_slug'])) {
                $validator->errors()->add(
                    "conditions.$index.post_type_slug",
                    'Post type is required when source type is post type.'
                );
            }

            if ($sourceType === 'taxonomy') {
                if (empty($condition['post_type_slug'])) {
                    $validator->errors()->add(
                        "conditions.$index.post_type_slug",
                        'Post type is required when source type is taxonomy.'
                    );
                }

                if (empty($condition['taxonomy_slug'])) {
                    $validator->errors()->add(
                        "conditions.$index.taxonomy_slug",
                        'Taxonomy is required when source type is taxonomy.'
                    );
                }

                if (
                    array_key_exists('taxonomy_term_ids', $condition) &&
                    !is_array($condition['taxonomy_term_ids'])
                ) {
                    $validator->errors()->add(
                        "conditions.$index.taxonomy_term_ids",
                        'Taxonomy terms must be an array.'
                    );
                }
            }
        }
    }

    private function getPostTypesForDropdown(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('post_types')) {
            return [
                [
                    'id' => null,
                    'label' => 'Property Listing',
                    'value' => 'property-listing',
                ],
                [
                    'id' => null,
                    'label' => 'Project Listing',
                    'value' => 'project-listing',
                ],
                [
                    'id' => null,
                    'label' => 'Developer Listing',
                    'value' => 'developer-listing',
                ],
                [
                    'id' => null,
                    'label' => 'Page',
                    'value' => 'page',
                ],
            ];
        }

        return DB::table('post_types')
            ->select('id', 'name', 'slug')
            ->orderBy('name')
            ->get()
            ->map(function ($postType) {
                return [
                    'id' => $postType->id,
                    'label' => $postType->name,
                    'value' => $postType->slug,
                ];
            })
            ->values()
            ->toArray();
    }

    private function getTaxonomiesForDropdown(): array
    {
        if (
            !DB::getSchemaBuilder()->hasTable('taxonomies') ||
            !DB::getSchemaBuilder()->hasTable('taxonomy_terms')
        ) {
            return [];
        }

        $taxonomies = DB::table('taxonomies')
            ->select('id', 'name', 'slug')
            ->orderBy('name')
            ->get();

        return $taxonomies->map(function ($taxonomy) {
            $terms = DB::table('taxonomy_terms')
                ->where('taxonomy_id', $taxonomy->id)
                ->select('id', 'name', 'slug')
                ->orderBy('name')
                ->get()
                ->map(function ($term) {
                    return [
                        'id' => $term->id,
                        'label' => $term->name,
                        'value' => $term->id,
                        'slug' => $term->slug,
                    ];
                })
                ->values()
                ->toArray();

            return [
                'id' => $taxonomy->id,
                'label' => $taxonomy->name,
                'value' => $taxonomy->slug,
                'terms' => $terms,
            ];
        })
        ->values()
        ->toArray();
    }

    private function generateShortcode($templateId): string
    {
        return '[vk_template id="' . $templateId . '"]';
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