<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TemplateApiController extends Controller
{
    public function resolve(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_type' => 'nullable|in:single_post,page,section',

            'post_type_id' => 'nullable|integer|exists:post_types,id',
            'post_type' => 'nullable|string',

            'taxonomy_term_ids' => 'nullable|array',
            'taxonomy_term_ids.*' => 'integer',

            'taxonomy_terms' => 'nullable|array',

            'id' => 'nullable',
            'slug' => 'nullable|string',
            'content_data' => 'nullable|array',
        ]);

        $validator->after(function ($validator) use ($payload) {
            $templateType = $payload['template_type'] ?? 'single_post';

            if ($templateType === 'single_post') {
                if (empty($payload['post_type_id']) && empty($payload['post_type'])) {
                    $validator->errors()->add(
                        'post_type',
                        'Post type id or post type slug is required.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $payload = $this->normalizePayload($payload);

        $templateType = $payload['template_type'] ?? 'single_post';

        $templates = Template::with([
                'conditions' => function ($query) {
                    $query->orderBy('id', 'asc');
                },
                'layout',
            ])
            ->where('status', 'active')
            ->where('template_type', $templateType)
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        foreach ($templates as $template) {
            if ($this->templateMatches($template, $payload)) {
                return response()->json([
                    'status' => true,
                    'message' => 'Matching template found.',
                    'data' => $this->makeRenderResponse($template, $payload),
                ]);
            }
        }

        return response()->json([
            'status' => false,
            'message' => 'No matching template found.',
            'data' => null,
        ], 404);
    }

    private function templateMatches($template, array $payload): bool
    {
        $conditions = $template->conditions
            ->sortBy('id')
            ->values();

        $excludeConditions = $conditions
            ->where('show_type', 'exclude')
            ->values();

        if ($excludeConditions->isNotEmpty()) {
            if ($this->evaluateConditionExpression($excludeConditions, $payload)) {
                return false;
            }
        }

        $includeConditions = $conditions
            ->where('show_type', 'include')
            ->values();

        if ($includeConditions->isNotEmpty()) {
            return $this->evaluateConditionExpression($includeConditions, $payload);
        }

        return $this->templateBaseMatches($template, $payload);
    }

    private function templateBaseMatches($template, array $payload): bool
    {
        if (($payload['template_type'] ?? 'single_post') !== 'single_post') {
            return true;
        }

        if (!empty($template->post_type_id) && !empty($payload['_post_type_id'])) {
            return (int) $template->post_type_id === (int) $payload['_post_type_id'];
        }

        if (!empty($template->post_type_slug) && !empty($payload['_post_type_slug'])) {
            return (string) $template->post_type_slug === (string) $payload['_post_type_slug'];
        }

        return false;
    }

    private function evaluateConditionExpression($conditions, array $payload): bool
    {
        /*
         * Rule 1 AND Rule 2 OR Rule 3 AND Rule 4
         * =
         * (Rule 1 AND Rule 2) OR (Rule 3 AND Rule 4)
         */

        $groups = [];
        $currentGroup = [];

        foreach ($conditions as $index => $condition) {
            $relation = strtolower((string) ($condition->relation ?? 'and'));

            if ($index > 0 && $relation === 'or') {
                $groups[] = $currentGroup;
                $currentGroup = [];
            }

            $currentGroup[] = $condition;
        }

        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        foreach ($groups as $group) {
            $groupMatched = true;

            foreach ($group as $condition) {
                if (!$this->conditionMatches($condition, $payload)) {
                    $groupMatched = false;
                    break;
                }
            }

            if ($groupMatched) {
                return true;
            }
        }

        return false;
    }

    private function conditionMatches($condition, array $payload): bool
    {
        if ($condition->source_type === 'post_type') {
            return $this->postTypeConditionMatches($condition, $payload);
        }

        if ($condition->source_type === 'taxonomy') {
            return $this->taxonomyConditionMatches($condition, $payload);
        }

        return false;
    }

    private function postTypeConditionMatches($condition, array $payload): bool
    {
        if (!empty($condition->post_type_id) && !empty($payload['_post_type_id'])) {
            return (int) $condition->post_type_id === (int) $payload['_post_type_id'];
        }

        if (!empty($condition->post_type_slug) && !empty($payload['_post_type_slug'])) {
            return (string) $condition->post_type_slug === (string) $payload['_post_type_slug'];
        }

        return false;
    }

    private function taxonomyConditionMatches($condition, array $payload): bool
    {
        if (!$this->postTypeConditionMatches($condition, $payload)) {
            return false;
        }

        $conditionTermIds = $condition->taxonomy_term_ids ?? [];

        if (!is_array($conditionTermIds)) {
            $conditionTermIds = [];
        }

        if (empty($conditionTermIds)) {
            return false;
        }

        $requestTermIds = $this->getRequestTermIdsForCondition($condition, $payload);

        if (empty($requestTermIds)) {
            return false;
        }

        $conditionTermIds = array_map('intval', $conditionTermIds);
        $requestTermIds = array_map('intval', $requestTermIds);

        return count(array_intersect($conditionTermIds, $requestTermIds)) > 0;
    }

    private function getRequestTermIdsForCondition($condition, array $payload): array
    {
        $termIds = [];

        if (!empty($payload['taxonomy_term_ids']) && is_array($payload['taxonomy_term_ids'])) {
            $termIds = array_merge($termIds, $payload['taxonomy_term_ids']);
        }

        if (!empty($payload['taxonomy_terms']) && is_array($payload['taxonomy_terms'])) {
            $taxonomyId = (string) ($condition->taxonomy_id ?? '');
            $taxonomySlug = (string) ($condition->taxonomy_slug ?? '');

            if ($taxonomyId !== '' && isset($payload['taxonomy_terms'][$taxonomyId])) {
                if (is_array($payload['taxonomy_terms'][$taxonomyId])) {
                    $termIds = array_merge($termIds, $payload['taxonomy_terms'][$taxonomyId]);
                }
            }

            if ($taxonomySlug !== '' && isset($payload['taxonomy_terms'][$taxonomySlug])) {
                if (is_array($payload['taxonomy_terms'][$taxonomySlug])) {
                    $termIds = array_merge($termIds, $payload['taxonomy_terms'][$taxonomySlug]);
                }
            }
        }

        return array_values(array_unique(array_filter($termIds, function ($id) {
            return $id !== null && $id !== '';
        })));
    }

    private function normalizePayload(array $payload): array
    {
        $postType = null;

        if (!empty($payload['post_type_id'])) {
            $postType = PostType::where('id', $payload['post_type_id'])->first();
        }

        if (!$postType && !empty($payload['post_type'])) {
            $postType = PostType::where('slug', $payload['post_type'])->first();
        }

        $payload['_post_type_id'] = $postType?->id ?? ($payload['post_type_id'] ?? null);
        $payload['_post_type_slug'] = $postType?->slug ?? ($payload['post_type'] ?? null);
        $payload['_post_type_name'] = $postType?->name ?? null;

        return $payload;
    }

    private function makeRenderResponse($template, array $payload): array
    {
        $layoutJson = $template->layout?->layout_json ?? [
            'sections' => []
        ];

        return [
            'template' => [
                'id' => $template->id,
                'template_type' => $template->template_type,
                'template_name' => $template->template_name,
                'slug' => $template->slug,
                'shortcode' => $template->shortcode,
                'post_type_id' => $template->post_type_id,
                'post_type_slug' => $template->post_type_slug,
                'priority' => $template->priority ?? 0,
            ],
            'request' => [
                'template_type' => $payload['template_type'] ?? 'single_post',
                'post_type_id' => $payload['_post_type_id'] ?? null,
                'post_type' => $payload['_post_type_slug'] ?? null,
                'id' => $payload['id'] ?? null,
                'slug' => $payload['slug'] ?? null,
                'taxonomy_term_ids' => $payload['taxonomy_term_ids'] ?? [],
            ],
            'display_conditions' => [
                'expression' => $this->buildExpressionFromConditions($template->conditions),
            ],
            'layout_json' => $layoutJson,
            'render_schema' => $this->normalizeLayoutForRender($layoutJson),
            'content_data' => $payload['content_data'] ?? [],
        ];
    }

    private function buildExpressionFromConditions($conditions): string
    {
        $conditions = $conditions
            ->where('show_type', 'include')
            ->sortBy('id')
            ->values();

        if ($conditions->isEmpty()) {
            return '';
        }

        $parts = [];

        foreach ($conditions as $index => $condition) {
            $ruleLabel = 'Rule ' . ($index + 1);

            if ($index === 0) {
                $parts[] = $ruleLabel;
                continue;
            }

            $parts[] = strtoupper($condition->relation ?? 'and') . ' ' . $ruleLabel;
        }

        $expression = implode(' ', $parts);

        if (str_contains($expression, ' OR ')) {
            $groups = explode(' OR ', $expression);

            $groups = array_map(function ($group) {
                return '(' . trim($group) . ')';
            }, $groups);

            return implode(' OR ', $groups);
        }

        return $expression;
    }

    private function normalizeLayoutForRender($layoutJson): array
    {
        if (is_string($layoutJson)) {
            $decoded = json_decode($layoutJson, true);
            $layoutJson = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        $sections = $layoutJson['sections'] ?? [];

        return [
            'sections' => collect($sections)->map(function ($section, $sectionIndex) {
                return [
                    'id' => $section['id'] ?? 'section_' . ($sectionIndex + 1),
                    'name' => $section['name'] ?? 'Section',
                    'sort_order' => $section['sort_order'] ?? ($sectionIndex + 1),
                    'settings' => $section['settings'] ?? [],
                    'rows' => collect($section['rows'] ?? [])->map(function ($row, $rowIndex) {
                        return [
                            'id' => $row['id'] ?? 'row_' . ($rowIndex + 1),
                            'sort_order' => $row['sort_order'] ?? ($rowIndex + 1),
                            'columns' => collect($row['columns'] ?? [])->map(function ($column, $columnIndex) {
                                return [
                                    'id' => $column['id'] ?? 'column_' . ($columnIndex + 1),
                                    'width' => $column['width'] ?? 12,
                                    'sort_order' => $column['sort_order'] ?? ($columnIndex + 1),
                                    'settings' => $column['settings'] ?? [],
                                    'components' => collect($column['components'] ?? [])->map(function ($component, $componentIndex) {
                                        return [
                                            'id' => $component['id'] ?? 'component_' . ($componentIndex + 1),
                                            'key' => $component['key'] ?? null,
                                            'name' => $component['name'] ?? null,
                                            'sort_order' => $component['sort_order'] ?? ($componentIndex + 1),
                                            'settings' => $component['settings'] ?? [],
                                            'binding' => $component['binding'] ?? [
                                                'source' => $component['settings']['source'] ?? null,
                                                'field' => $component['settings']['field'] ?? null,
                                            ],
                                        ];
                                    })->values()->toArray(),
                                ];
                            })->values()->toArray(),
                        ];
                    })->values()->toArray(),
                ];
            })->values()->toArray(),
        ];
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

    private function validationErrorResponse($validator)
    {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }
}