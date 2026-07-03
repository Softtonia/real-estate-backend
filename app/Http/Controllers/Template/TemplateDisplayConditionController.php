<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TemplateDisplayConditionController extends Controller
{
    protected string $table = 'template_display_conditions';

    public function index($template_id): JsonResponse
    {
        $template = Template::find($template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $conditions = DB::table($this->table)
            ->where('template_id', $template->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($condition) => $this->formatCondition($condition))
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Template display conditions fetched successfully.',
            'data' => [
                'template_id' => $template->id,
                'conditions' => $conditions,
            ],
        ]);
    }

    public function replace(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $conditions = $payload['conditions']
            ?? $payload['display_conditions']
            ?? $payload['rules']
            ?? [];

        $payload['conditions'] = $conditions;

        $validator = Validator::make($payload, [
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'conditions' => ['required', 'array'],

            'conditions.*.show_type' => ['nullable', 'in:include,exclude'],
            'conditions.*.source_type' => ['nullable', 'in:post_type,taxonomy'],
            'conditions.*.post_type_id' => ['nullable', 'integer'],
            'conditions.*.post_type_slug' => ['nullable', 'string'],
            'conditions.*.taxonomy_id' => ['nullable', 'integer'],
            'conditions.*.taxonomy_slug' => ['nullable', 'string'],
            'conditions.*.taxonomy_term_ids' => ['nullable'],
            'conditions.*.term_ids' => ['nullable'],
            'conditions.*.relation' => ['nullable', 'in:and,or,AND,OR'],
            'conditions.*.condition_value' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $template = Template::find((int) $payload['template_id']);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        try {
            DB::transaction(function () use ($template, $conditions) {
                DB::table($this->table)
                    ->where('template_id', $template->id)
                    ->delete();

                foreach ($conditions as $condition) {
                    if (! is_array($condition)) {
                        continue;
                    }

                    $data = $this->normalizeCondition($condition, $template);

                    DB::table($this->table)->insert($data);
                }
            });

            $this->clearTemplateCache((int) $template->id);

            $savedConditions = DB::table($this->table)
                ->where('template_id', $template->id)
                ->orderBy('id')
                ->get()
                ->map(fn ($condition) => $this->formatCondition($condition))
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Template display conditions replaced successfully.',
                'data' => [
                    'template_id' => $template->id,
                    'conditions' => $savedConditions,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to replace template display conditions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $condition = $payload['condition'] ?? $payload;

        $validator = Validator::make($condition, [
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'show_type' => ['nullable', 'in:include,exclude'],
            'source_type' => ['nullable', 'in:post_type,taxonomy'],
            'post_type_id' => ['nullable', 'integer'],
            'post_type_slug' => ['nullable', 'string'],
            'taxonomy_id' => ['nullable', 'integer'],
            'taxonomy_slug' => ['nullable', 'string'],
            'taxonomy_term_ids' => ['nullable'],
            'term_ids' => ['nullable'],
            'relation' => ['nullable', 'in:and,or,AND,OR'],
            'condition_value' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $template = Template::find((int) $condition['template_id']);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        try {
            $data = $this->normalizeCondition($condition, $template);

            $id = DB::table($this->table)->insertGetId($data);

            $this->clearTemplateCache((int) $template->id);

            $savedCondition = DB::table($this->table)->where('id', $id)->first();

            return response()->json([
                'status' => true,
                'message' => 'Template display condition created successfully.',
                'data' => $this->formatCondition($savedCondition),
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to create template display condition.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $conditionPayload = $payload['condition'] ?? $payload;

        $conditionId = $conditionPayload['id']
            ?? $conditionPayload['condition_id']
            ?? null;

        if (! $conditionId) {
            return response()->json([
                'status' => false,
                'message' => 'Condition id is required.',
            ], 422);
        }

        $existingCondition = DB::table($this->table)
            ->where('id', (int) $conditionId)
            ->first();

        if (! $existingCondition) {
            return response()->json([
                'status' => false,
                'message' => 'Template display condition not found.',
            ], 404);
        }

        $template = Template::find((int) $existingCondition->template_id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $conditionPayload['template_id'] = $template->id;

        $validator = Validator::make($conditionPayload, [
            'id' => ['nullable', 'integer'],
            'condition_id' => ['nullable', 'integer'],
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'show_type' => ['nullable', 'in:include,exclude'],
            'source_type' => ['nullable', 'in:post_type,taxonomy'],
            'post_type_id' => ['nullable', 'integer'],
            'post_type_slug' => ['nullable', 'string'],
            'taxonomy_id' => ['nullable', 'integer'],
            'taxonomy_slug' => ['nullable', 'string'],
            'taxonomy_term_ids' => ['nullable'],
            'term_ids' => ['nullable'],
            'relation' => ['nullable', 'in:and,or,AND,OR'],
            'condition_value' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $data = $this->normalizeCondition($conditionPayload, $template, false);

            DB::table($this->table)
                ->where('id', (int) $conditionId)
                ->update($data);

            $this->clearTemplateCache((int) $template->id);

            $updatedCondition = DB::table($this->table)
                ->where('id', (int) $conditionId)
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Template display condition updated successfully.',
                'data' => $this->formatCondition($updatedCondition),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update template display condition.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $condition = DB::table($this->table)
            ->where('id', (int) $id)
            ->first();

        if (! $condition) {
            return response()->json([
                'status' => false,
                'message' => 'Template display condition not found.',
            ], 404);
        }

        try {
            DB::table($this->table)
                ->where('id', (int) $id)
                ->delete();

            $this->clearTemplateCache((int) $condition->template_id);

            return response()->json([
                'status' => true,
                'message' => 'Template display condition deleted successfully.',
                'data' => [
                    'id' => (int) $id,
                    'template_id' => (int) $condition->template_id,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete template display condition.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function normalizeCondition(array $condition, Template $template, bool $isCreate = true): array
    {
        $sourceType = $condition['source_type']
            ?? $condition['show_if']
            ?? 'post_type';

        $sourceType = in_array($sourceType, ['post_type', 'taxonomy'], true)
            ? $sourceType
            : 'post_type';

        $showType = $condition['show_type']
            ?? 'include';

        $showType = in_array($showType, ['include', 'exclude'], true)
            ? $showType
            : 'include';

        $relation = strtolower((string) ($condition['relation'] ?? 'and'));

        $relation = in_array($relation, ['and', 'or'], true)
            ? $relation
            : 'and';

        $postTypeId = $condition['post_type_id']
            ?? null;

        $postTypeSlug = $condition['post_type_slug']
            ?? $condition['post_type']
            ?? null;

        /*
         * For post_type condition, fallback to template post type.
         */
        if ($sourceType === 'post_type') {
            $postTypeId = $postTypeId ?: $template->post_type_id;
            $postTypeSlug = $postTypeSlug ?: $template->post_type_slug;

            $postType = $this->resolvePostType($postTypeId, $postTypeSlug);

            $postTypeId = $postType?->id ?? $postTypeId;
            $postTypeSlug = $postType?->slug ?? $postTypeSlug;
        }

        $taxonomyTermIds = $condition['taxonomy_term_ids']
            ?? $condition['term_ids']
            ?? $condition['terms']
            ?? [];

        $taxonomyTermIds = $this->normalizeIds($taxonomyTermIds);

        $data = [
            'template_id' => $template->id,
            'show_type' => $showType,
            'source_type' => $sourceType,

            'post_type_id' => $sourceType === 'post_type' ? $postTypeId : null,
            'post_type_slug' => $sourceType === 'post_type' ? $postTypeSlug : null,

            'taxonomy_id' => $sourceType === 'taxonomy'
                ? ($condition['taxonomy_id'] ?? null)
                : null,

            'taxonomy_slug' => $sourceType === 'taxonomy'
                ? ($condition['taxonomy_slug'] ?? $condition['taxonomy'] ?? null)
                : null,

            /*
             * Store as JSON string because query builder cannot insert PHP array.
             * TemplateResolveService already supports JSON string decoding.
             */
            'taxonomy_term_ids' => $sourceType === 'taxonomy'
                ? json_encode($taxonomyTermIds)
                : json_encode([]),

            'relation' => $relation,
            'condition_value' => $this->normalizeConditionValue($condition['condition_value'] ?? null),
        ];

        if ($isCreate && Schema::hasColumn($this->table, 'created_at')) {
            $data['created_at'] = now();
        }

        if (Schema::hasColumn($this->table, 'updated_at')) {
            $data['updated_at'] = now();
        }

        if ($isCreate && Schema::hasColumn($this->table, 'created_by')) {
            $data['created_by'] = auth()->id();
        }

        if (! $isCreate && Schema::hasColumn($this->table, 'updated_by')) {
            $data['updated_by'] = auth()->id();
        }

        return $this->filterExistingColumns($data);
    }

    private function formatCondition(?object $condition): ?array
    {
        if (! $condition) {
            return null;
        }

        $row = json_decode(json_encode($condition), true) ?: [];

        $row['taxonomy_term_ids'] = $this->decodeMaybeJson($row['taxonomy_term_ids'] ?? []);

        if (! is_array($row['taxonomy_term_ids'])) {
            $row['taxonomy_term_ids'] = [];
        }

        $row['taxonomy_term_ids'] = $this->normalizeIds($row['taxonomy_term_ids']);

        if (array_key_exists('condition_value', $row)) {
            $row['condition_value'] = $this->decodeMaybeJson($row['condition_value']);
        }

        return $row;
    }

    private function resolvePostType(mixed $postTypeId = null, mixed $postTypeSlug = null): ?PostType
    {
        if ($postTypeId) {
            $postType = PostType::query()
                ->where('id', (int) $postTypeId)
                ->first();

            if ($postType) {
                return $postType;
            }
        }

        if ($postTypeSlug) {
            return PostType::query()
                ->where('slug', (string) $postTypeSlug)
                ->first();
        }

        return null;
    }

    private function normalizeIds(mixed $value): array
    {
        $value = $this->decodeMaybeJson($value);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatten()
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeConditionValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return $value;
    }

    private function decodeMaybeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private function filterExistingColumns(array $data): array
    {
        return collect($data)
            ->filter(function ($value, string $column) {
                return Schema::hasColumn($this->table, $column);
            })
            ->all();
    }

    private function clearTemplateCache(int $templateId): void
    {
        try {
            if (class_exists(\App\PageBuilder\Services\TemplateRenderCacheService::class)) {
                app(\App\PageBuilder\Services\TemplateRenderCacheService::class)
                    ->clearTemplate($templateId);
            }
        } catch (Throwable) {
            //
        }
    }

    private function validationError($validator): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
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