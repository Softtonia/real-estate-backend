<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\TemplateDisplayCondition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'template_id' => 'required|exists:templates,id',
            'conditions' => 'required|array|min:1',

            'conditions.*.show_type' => 'required|in:include,exclude',
            'conditions.*.source_type' => 'required|in:post_type,taxonomy',
            'conditions.*.post_type_slug' => 'required|string|max:255',
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
            $template = Template::find($payload['template_id']);

            $createdConditions = [];

            foreach ($payload['conditions'] as $conditionData) {
                $createdConditions[] = $this->createCondition(
                    $template->id,
                    $conditionData
                );
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Template conditions created successfully.',
                'data' => $createdConditions,
            ], 201);
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

        $validator = Validator::make($payload, [
            'template_id' => 'required|exists:templates,id',
            'conditions' => 'required|array|min:1',

            'conditions.*.id' => 'nullable|exists:template_display_conditions,id',
            'conditions.*.show_type' => 'required|in:include,exclude',
            'conditions.*.source_type' => 'required|in:post_type,taxonomy',
            'conditions.*.post_type_slug' => 'required|string|max:255',
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
            $savedConditions = [];

            foreach ($payload['conditions'] as $conditionData) {
                if (!empty($conditionData['id'])) {
                    $condition = TemplateDisplayCondition::where('id', $conditionData['id'])
                        ->where('template_id', $payload['template_id'])
                        ->first();

                    if (!$condition) {
                        continue;
                    }

                    $condition->update($this->prepareConditionData(
                        $payload['template_id'],
                        $conditionData
                    ));

                    $savedConditions[] = $condition->fresh();
                } else {
                    $savedConditions[] = $this->createCondition(
                        $payload['template_id'],
                        $conditionData
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Template conditions updated successfully.',
                'data' => $savedConditions,
            ]);
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

        $validator = Validator::make($payload, [
            'template_id' => 'required|exists:templates,id',
            'conditions' => 'nullable|array',

            'conditions.*.show_type' => 'required_with:conditions|in:include,exclude',
            'conditions.*.source_type' => 'required_with:conditions|in:post_type,taxonomy',
            'conditions.*.post_type_slug' => 'required_with:conditions|string|max:255',
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
            TemplateDisplayCondition::where('template_id', $payload['template_id'])->delete();

            $savedConditions = [];

            foreach ($payload['conditions'] ?? [] as $conditionData) {
                $savedConditions[] = $this->createCondition(
                    $payload['template_id'],
                    $conditionData
                );
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Template conditions replaced successfully.',
                'data' => $savedConditions,
            ]);
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

        $condition->delete();

        return response()->json([
            'status' => true,
            'message' => 'Template condition deleted successfully.',
        ]);
    }

    private function createCondition($templateId, array $conditionData)
    {
        return TemplateDisplayCondition::create(
            $this->prepareConditionData($templateId, $conditionData)
        );
    }

    private function prepareConditionData($templateId, array $conditionData): array
    {
        $sourceType = $conditionData['source_type'];
        $postTypeSlug = $conditionData['post_type_slug'];
        $taxonomySlug = $sourceType === 'taxonomy'
            ? ($conditionData['taxonomy_slug'] ?? null)
            : null;

        $taxonomyTermIds = $sourceType === 'taxonomy'
            ? ($conditionData['taxonomy_term_ids'] ?? [])
            : [];

        return [
            'template_id' => $templateId,
            'show_type' => $conditionData['show_type'],

            // new columns
            'source_type' => $sourceType,
            'post_type_slug' => $postTypeSlug,
            'taxonomy_slug' => $taxonomySlug,
            'taxonomy_term_ids' => $taxonomyTermIds,
            'relation' => $conditionData['relation'] ?? 'and',

            // old columns support
            'post_type' => $postTypeSlug,
            'condition_type' => $sourceType,
            'condition_value' => $sourceType === 'taxonomy'
                ? [
                    'taxonomy_slug' => $taxonomySlug,
                    'taxonomy_term_ids' => $taxonomyTermIds,
                ]
                : $postTypeSlug,
        ];
    }

    private function validateConditions($validator, array $conditions): void
    {
        foreach ($conditions as $index => $condition) {
            $sourceType = $condition['source_type'] ?? null;

            if ($sourceType === 'post_type') {
                if (empty($condition['post_type_slug'])) {
                    $validator->errors()->add(
                        "conditions.$index.post_type_slug",
                        'Post type is required.'
                    );
                }
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
                    isset($condition['taxonomy_term_ids']) &&
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