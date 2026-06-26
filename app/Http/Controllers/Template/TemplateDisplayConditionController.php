<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use App\Models\Taxonomy;
use App\Models\Template;
use App\Models\TemplateDisplayCondition;
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
                if (!empty($conditionData['id'])) {
                    $condition = TemplateDisplayCondition::where('id', $conditionData['id'])
                        ->where('template_id', $template->id)
                        ->first();

                    if (!$condition) {
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

        if (!$condition) {
            return response()->json([
                'status' => false,
                'message' => 'Template condition not found.',
            ], 404);
        }

        $templateId = $condition->template_id;

        $condition->delete();

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
            'conditions.*.show_if' => 'required_with:conditions|in:post_type,taxonomy',

            /*
             * Important:
             * post_type_id nullable rakha hai.
             * Isko manually validateConditions() me only post_type condition ke liye required kiya hai.
             */
            'conditions.*.post_type_id' => 'nullable|integer|exists:post_types,id',

            'conditions.*.taxonomy_id' => 'nullable|integer|exists:taxonomies,id',
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
            $showIf = $condition['show_if'] ?? null;

            /*
             * If condition is post_type,
             * then only post_type_id is required.
             */
            if ($showIf === 'post_type') {
                if (empty($condition['post_type_id'])) {
                    $validator->errors()->add(
                        "conditions.$index.post_type_id",
                        'Post type is required when show_if is post_type.'
                    );
                }
            }

            /*
             * If condition is taxonomy,
             * then taxonomy_id and taxonomy_term_ids are required.
             * post_type_id is NOT required here.
             */
            if ($showIf === 'taxonomy') {
                if (empty($condition['taxonomy_id'])) {
                    $validator->errors()->add(
                        "conditions.$index.taxonomy_id",
                        'Taxonomy is required when show_if is taxonomy.'
                    );
                }

                if (
                    empty($condition['taxonomy_term_ids']) ||
                    !is_array($condition['taxonomy_term_ids'])
                ) {
                    $validator->errors()->add(
                        "conditions.$index.taxonomy_term_ids",
                        'Taxonomy terms are required and must be an array.'
                    );
                }
            }
        }
    }

    private function prepareConditionData(Template $template, array $conditionData): array
    {
        $showIf = $conditionData['show_if'] ?? $conditionData['source_type'] ?? null;

        $logicOperator = $conditionData['logic_operator']
            ?? $conditionData['relation']
            ?? 'and';

        $showType = $conditionData['show_type'] ?? 'include';

        $postType = null;
        $taxonomy = null;

        /*
         * Post type should be stored only when show_if is post_type.
         * Taxonomy condition me post_type_id store nahi hoga.
         */
        if ($showIf === 'post_type') {
            if (!empty($conditionData['post_type_id'])) {
                $postType = PostType::find($conditionData['post_type_id']);
            }

            if (!$postType && !empty($conditionData['post_type_slug'])) {
                $postType = PostType::where('slug', $conditionData['post_type_slug'])->first();
            }
        }

        /*
         * Taxonomy should be stored only when show_if is taxonomy.
         */
        if ($showIf === 'taxonomy') {
            if (!empty($conditionData['taxonomy_id'])) {
                $taxonomy = Taxonomy::find($conditionData['taxonomy_id']);
            }

            if (!$taxonomy && !empty($conditionData['taxonomy_slug'])) {
                $taxonomy = Taxonomy::where('slug', $conditionData['taxonomy_slug'])->first();
            }
        }

        $taxonomyTermIds = $showIf === 'taxonomy'
            ? ($conditionData['taxonomy_term_ids'] ?? [])
            : [];

        return [
            'template_id' => $template->id,

            'show_type' => $showType,
            'source_type' => $showIf,

            'post_type_id' => $postType?->id,
            'post_type_slug' => $postType?->slug,

            'taxonomy_id' => $taxonomy?->id,
            'taxonomy_slug' => $taxonomy?->slug,

            'taxonomy_term_ids' => $taxonomyTermIds,

            'relation' => $logicOperator,

            'condition_value' => [
                'show_type' => $showType,
                'logic_operator' => $logicOperator,
                'show_if' => $showIf,

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

        if (!$template) {
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

                /*
                 * First rule ka logic_operator null rahega.
                 * Second rule onwards relation with previous rule hoga.
                 */
                'logic_operator' => $index === 0 ? null : $condition->relation,

                'show_type' => $condition->show_type,
                'show_if' => $condition->source_type,

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

        /*
         * Rule 1 AND Rule 2 OR Rule 3 AND Rule 4
         * Visual output:
         * (Rule 1 AND Rule 2) OR (Rule 3 AND Rule 4)
         */
        if (str_contains($expression, ' OR ')) {
            $groups = explode(' OR ', $expression);

            $groups = array_map(function ($group) {
                return '(' . trim($group) . ')';
            }, $groups);

            return implode(' OR ', $groups);
        }

        return $expression;
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