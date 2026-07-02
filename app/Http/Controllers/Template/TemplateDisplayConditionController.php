<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use App\Models\Taxonomy;
use App\Models\Template;
use App\Models\TemplateDisplayCondition;
use App\PageBuilder\Services\TemplateRenderCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TemplateDisplayConditionController extends Controller
{
    public function index($template_id)
    {
        return $this->templateConditionsResponse(
            $template_id,
            'Template conditions fetched successfully.'
        );
    }

    public function create(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = $this->makeValidator($payload, true, false);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $template = Template::find($payload['template_id']);

            foreach ($payload['conditions'] as $conditionData) {
                TemplateDisplayCondition::create(
                    $this->prepareConditionData($template, $conditionData)
                );
            }

            DB::commit();

            $this->clearTemplateCache((int) $template->id);

            return $this->templateConditionsResponse(
                $template->id,
                'Template conditions created successfully.',
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to create template conditions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = $this->makeValidator($payload, true, true);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $template = Template::find($payload['template_id']);

            foreach ($payload['conditions'] as $conditionData) {
                if (! empty($conditionData['id'])) {
                    $condition = TemplateDisplayCondition::where('id', $conditionData['id'])
                        ->where('template_id', $template->id)
                        ->first();

                    if (! $condition) {
                        continue;
                    }

                    $condition->update(
                        $this->prepareConditionData($template, $conditionData)
                    );
                } else {
                    TemplateDisplayCondition::create(
                        $this->prepareConditionData($template, $conditionData)
                    );
                }
            }

            DB::commit();

            $this->clearTemplateCache((int) $template->id);

            return $this->templateConditionsResponse(
                $template->id,
                'Template conditions updated successfully.'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to update template conditions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function replace(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = $this->makeValidator($payload, false, false);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $template = Template::find($payload['template_id']);

            TemplateDisplayCondition::where('template_id', $template->id)->delete();

            foreach ($payload['conditions'] ?? [] as $conditionData) {
                TemplateDisplayCondition::create(
                    $this->prepareConditionData($template, $conditionData)
                );
            }

            DB::commit();

            $this->clearTemplateCache((int) $template->id);

            return $this->templateConditionsResponse(
                $template->id,
                'Template conditions replaced successfully.'
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to replace template conditions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $condition = TemplateDisplayCondition::find($id);

        if (! $condition) {
            return response()->json([
                'status' => false,
                'message' => 'Template condition not found.',
            ], 404);
        }

        $templateId = (int) $condition->template_id;

        $condition->delete();

        $this->clearTemplateCache($templateId);

        return $this->templateConditionsResponse(
            $templateId,
            'Template condition deleted successfully.'
        );
    }

    private function makeValidator(array $payload, bool $conditionsRequired = true, bool $allowId = false)
    {
        $conditionRule = $conditionsRequired ? 'required|array|min:1' : 'nullable|array';

        $rules = [
            'template_id' => 'required|exists:templates,id',
            'conditions' => $conditionRule,

            'conditions.*.logic_operator' => 'nullable|in:and,or',
            'conditions.*.relation' => 'nullable|in:and,or',

            'conditions.*.show_type' => 'required_with:conditions|in:include,exclude',
            'conditions.*.source_type' => 'required_with:conditions|in:post_type,taxonomy',

            'conditions.*.post_type_id' => 'nullable|integer|exists:post_types,id',
            'conditions.*.post_type_slug' => 'nullable|string',

            'conditions.*.taxonomy_id' => 'nullable|integer|exists:taxonomies,id',
            'conditions.*.taxonomy_slug' => 'nullable|string',
            'conditions.*.taxonomy_term_ids' => 'nullable|array',
            'conditions.*.taxonomy_term_ids.*' => 'integer',
        ];

        if ($allowId) {
            $rules['conditions.*.id'] = 'nullable|exists:template_display_conditions,id';
        }

        $validator = Validator::make($payload, $rules);

        $validator->after(function ($validator) use ($payload) {
            $this->validateConditions($validator, $payload['conditions'] ?? []);
        });

        return $validator;
    }

    private function validateConditions($validator, array $conditions): void
    {
        foreach ($conditions as $index => $condition) {
            $sourceType = $condition['source_type'] ?? null;

            if ($sourceType === 'post_type') {
                if (
                    empty($condition['post_type_id'])
                    && empty($condition['post_type_slug'])
                ) {
                    $validator->errors()->add(
                        "conditions.$index.post_type",
                        'Post type id or post type slug is required when source_type is post_type.'
                    );
                }

                if (
                    empty($condition['post_type_id'])
                    && ! empty($condition['post_type_slug'])
                    && ! PostType::where('slug', $condition['post_type_slug'])->exists()
                ) {
                    $validator->errors()->add(
                        "conditions.$index.post_type_slug",
                        'Selected post type slug is invalid.'
                    );
                }
            }

            if ($sourceType === 'taxonomy') {
                if (
                    empty($condition['taxonomy_id'])
                    && empty($condition['taxonomy_slug'])
                ) {
                    $validator->errors()->add(
                        "conditions.$index.taxonomy",
                        'Taxonomy id or taxonomy slug is required when source_type is taxonomy.'
                    );
                }

                if (
                    empty($condition['taxonomy_id'])
                    && ! empty($condition['taxonomy_slug'])
                    && ! Taxonomy::where('slug', $condition['taxonomy_slug'])->exists()
                ) {
                    $validator->errors()->add(
                        "conditions.$index.taxonomy_slug",
                        'Selected taxonomy slug is invalid.'
                    );
                }
            }
        }
    }

    private function prepareConditionData(Template $template, array $conditionData): array
    {
        $sourceType = $conditionData['source_type'] ?? null;

        $logicOperator = $conditionData['logic_operator']
            ?? $conditionData['relation']
            ?? 'and';

        $showType = $conditionData['show_type'] ?? 'include';

        $postType = null;
        $taxonomy = null;

        if ($sourceType === 'post_type') {
            if (! empty($conditionData['post_type_id'])) {
                $postType = PostType::find($conditionData['post_type_id']);
            }

            if (! $postType && ! empty($conditionData['post_type_slug'])) {
                $postType = PostType::where('slug', $conditionData['post_type_slug'])->first();
            }
        }

        if ($sourceType === 'taxonomy') {
            if (! empty($conditionData['taxonomy_id'])) {
                $taxonomy = Taxonomy::find($conditionData['taxonomy_id']);
            }

            if (! $taxonomy && ! empty($conditionData['taxonomy_slug'])) {
                $taxonomy = Taxonomy::where('slug', $conditionData['taxonomy_slug'])->first();
            }
        }

        $taxonomyTermIds = $sourceType === 'taxonomy'
            ? ($conditionData['taxonomy_term_ids'] ?? [])
            : [];

        return [
            'template_id' => $template->id,

            'show_type' => $showType,
            'source_type' => $sourceType,

            'post_type_id' => $postType?->id,
            'post_type_slug' => $postType?->slug,

            'taxonomy_id' => $taxonomy?->id,
            'taxonomy_slug' => $taxonomy?->slug,

            'taxonomy_term_ids' => $taxonomyTermIds,

            'relation' => $logicOperator,

            'condition_value' => [
                'show_type' => $showType,
                'source_type' => $sourceType,
                'logic_operator' => $logicOperator,

                'post_type_id' => $postType?->id,
                'post_type_slug' => $postType?->slug,

                'taxonomy_id' => $taxonomy?->id,
                'taxonomy_slug' => $taxonomy?->slug,

                'taxonomy_term_ids' => $taxonomyTermIds,
            ],
        ];
    }

    private function templateConditionsResponse($templateId, string $message, int $statusCode = 200)
    {
        $template = Template::with([
            'conditions' => function ($query) {
                $query->orderBy('id', 'asc');
            },
            'layout',
        ])->find($templateId);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $rules = $this->formatRules($template->conditions);

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'template' => [
                    'id' => $template->id,
                    'template_type' => $template->template_type,
                    'template_name' => $template->template_name,
                    'post_type_id' => $template->post_type_id,
                    'post_type_slug' => $template->post_type_slug,
                    'slug' => $template->slug,
                    'shortcode' => $template->shortcode,
                    'status' => $template->status,
                    'priority' => $template->priority,
                ],
                'display_conditions' => [
                    'expression' => $this->buildExpression($rules),
                    'rules_count' => count($rules),
                    'rules' => $rules,
                ],
            ],
        ], $statusCode);
    }

    private function formatRules($conditions): array
    {
        return $conditions->values()->map(function ($condition, $index) {
            return [
                'id' => $condition->id,

                'logic_operator' => $index === 0 ? null : $condition->relation,

                'show_type' => $condition->show_type,
                'source_type' => $condition->source_type,

                'post_type_id' => $condition->post_type_id,
                'post_type_slug' => $condition->post_type_slug,

                'taxonomy_id' => $condition->taxonomy_id,
                'taxonomy_slug' => $condition->taxonomy_slug,
                'taxonomy_term_ids' => $condition->taxonomy_term_ids ?? [],
            ];
        })->toArray();
    }

    private function buildExpression(array $rules): string
    {
        if (empty($rules)) {
            return '';
        }

        $parts = [];

        foreach ($rules as $index => $rule) {
            $ruleLabel = 'Rule ' . ($index + 1);

            if ($index === 0) {
                $parts[] = $ruleLabel;
                continue;
            }

            $operator = strtoupper($rule['logic_operator'] ?? 'and');

            $parts[] = $operator . ' ' . $ruleLabel;
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

    private function clearTemplateCache(int $templateId): void
    {
        if (class_exists(TemplateRenderCacheService::class)) {
            app(TemplateRenderCacheService::class)->clearTemplate($templateId);
        }
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