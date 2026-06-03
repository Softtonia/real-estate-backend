<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TemplateApiController extends Controller
{
    public function resolve(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'post_type' => 'required|in:property-listing,project-listing,developer-listing',
            'purpose' => 'nullable|string',
            'property' => 'nullable|string',
            'property_type' => 'nullable|string',
            'property_status' => 'nullable|string',
            'project_status' => 'nullable|string',
            'developer' => 'nullable|string',
            'id' => 'nullable',
            'slug' => 'nullable|string',
            'content_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $templates = Template::with(['conditions', 'layout'])
            ->where('status', 'active')
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
        $postType = $payload['post_type'];

        $conditions = $template->conditions
            ->where('post_type', $postType)
            ->values();

        if ($conditions->isEmpty()) {
            return false;
        }

        $excludeConditions = $conditions->where('show_type', 'exclude')->values();

        foreach ($excludeConditions as $condition) {
            if ($this->singleConditionMatches($condition, $payload)) {
                return false;
            }
        }

        $includeConditions = $conditions->where('show_type', 'include')->values();

        if ($includeConditions->isEmpty()) {
            return false;
        }

        $hasAllCondition = $includeConditions
            ->where('condition_type', 'all')
            ->isNotEmpty();

        if ($hasAllCondition) {
            return true;
        }

        foreach ($includeConditions as $condition) {
            if (!$this->singleConditionMatches($condition, $payload)) {
                return false;
            }
        }

        return true;
    }

    private function singleConditionMatches($condition, array $payload): bool
    {
        if ($condition->condition_type === 'all') {
            return true;
        }

        $map = [
            'purpose' => 'purpose',
            'property' => 'property',
            'property-type' => 'property_type',
            'property-status' => 'property_status',
            'project-status' => 'project_status',
            'developer' => 'developer',
        ];

        $requestKey = $map[$condition->condition_type] ?? null;

        if (!$requestKey) {
            return false;
        }

        $requestValue = $payload[$requestKey] ?? null;

        if ($requestValue === null) {
            return false;
        }

        return strtolower((string) $requestValue) === strtolower((string) $condition->condition_value);
    }

    private function makeRenderResponse($template, array $payload): array
    {
        $layoutJson = $template->layout?->layout_json ?? [
            'sections' => []
        ];

        return [
            'template' => [
                'id' => $template->id,
                'template_name' => $template->template_name,
                'slug' => $template->slug,
                'priority' => $template->priority ?? 0,
            ],
            'request' => [
                'post_type' => $payload['post_type'],
                'id' => $payload['id'] ?? null,
                'slug' => $payload['slug'] ?? null,
            ],
            'layout_json' => $layoutJson,
            'render_schema' => $this->normalizeLayoutForRender($layoutJson),
            'content_data' => $payload['content_data'] ?? [],
        ];
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
}