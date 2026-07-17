<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DynamicPostFormSteps\SaveDynamicPostFormMappingRequest;
use App\Http\Requests\DynamicPostFormSteps\SaveDynamicPostFormStepsRequest;
use App\Models\DynamicPostFormStep;
use App\Services\DynamicPosts\DynamicPostFormStepService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class DynamicPostFormStepController extends Controller
{
    public function __construct(
        private readonly DynamicPostFormStepService $service
    ) {}

    public function builder(Request $request, int|string $postType): JsonResponse
    {
        try {
            $request->validate([
                'taxonomy_term_ids' => ['nullable'],
            ]);

            $postTypeData = $this->service->resolvePostType($postType);

            if (!$postTypeData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $this->service->ensureDefaultSteps($postTypeData);

            $termIds = $this->service->normalizeIds($request->taxonomy_term_ids);

            $steps = $this->service->steps($postTypeData);

            $customFields = $this->service->formattedCustomFields($postTypeData, $termIds, false);
            $fieldIds = $customFields
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            $mappedRows = $this->service->mappedFieldRows($postTypeData)
                ->whereIn('custom_field_id', $fieldIds);

            $mappedByFieldId = $mappedRows->keyBy('custom_field_id');

            $fieldsWithMapping = $customFields->map(function ($field) use ($mappedByFieldId) {
                $mapping = $mappedByFieldId->get((int) $field['id']);

                $field['is_mapped'] = (bool) $mapping;
                $field['mapping'] = $mapping ? [
                    'id' => (int) $mapping->id,
                    'step_id' => (int) $mapping->dynamic_post_form_step_id,
                    'step_key' => $mapping->step?->step_key,
                    'step_label' => $mapping->step?->step_label,
                    'sort_order' => (int) $mapping->sort_order,
                ] : null;

                return $field;
            });

            $stepsPayload = $steps->map(function (DynamicPostFormStep $step) use ($fieldsWithMapping) {
                $stepFields = $fieldsWithMapping
                    ->filter(fn($field) => ($field['mapping']['step_key'] ?? null) === $step->step_key)
                    ->sortBy(fn($field) => $field['mapping']['sort_order'] ?? 0)
                    ->values();

                return [
                    'id' => (int) $step->id,
                    'step_key' => $step->step_key,
                    'step_label' => $step->step_label,
                    'description' => $step->description,
                    'sort_order' => (int) $step->sort_order,
                    'is_active' => (bool) $step->is_active,
                    'custom_fields_count' => $stepFields->count(),
                    'custom_fields' => $stepFields,
                ];
            })->values();

            $unmappedFields = $fieldsWithMapping
                ->filter(fn($field) => empty($field['is_mapped']))
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Form step builder fetched successfully.',
                'data' => [
                    'post_type' => $this->service->postTypePayload($postTypeData),
                    'taxonomy_term_ids' => $termIds,
                    'steps' => $stepsPayload,
                    'custom_fields_count' => $fieldsWithMapping->count(),
                    'mapped_custom_fields_count' => $fieldsWithMapping->where('is_mapped', true)->count(),
                    'unmapped_custom_fields_count' => $unmappedFields->count(),
                    'unmapped_custom_fields' => $unmappedFields,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch form step builder.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveSteps(
        SaveDynamicPostFormStepsRequest $request,
        int|string $postType
    ): JsonResponse {
        try {
            $postTypeData = $this->service->resolvePostType($postType);

            if (!$postTypeData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $steps = $this->service->saveSteps(
                $postTypeData,
                $request->validated('steps')
            );

            return response()->json([
                'status' => true,
                'message' => 'Form steps saved successfully.',
                'data' => [
                    'post_type' => $this->service->postTypePayload($postTypeData),
                    'steps' => $steps,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to save form steps.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveMapping(
        SaveDynamicPostFormMappingRequest $request,
        int|string $postType
    ): JsonResponse {
        try {
            $postTypeData = $this->service->resolvePostType($postType);

            if (!$postTypeData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $this->service->ensureDefaultSteps($postTypeData);

            $termIds = $this->service->normalizeIds($request->input('taxonomy_term_ids'));

            $allowedCustomFieldIds = $this->service
                ->formattedCustomFields($postTypeData, $termIds, false)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            $this->service->syncMapping(
                $postTypeData,
                $request->validated('steps'),
                $allowedCustomFieldIds
            );

            return $this->builder($request, $postTypeData->id);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to save form field mapping.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function frontendForm(Request $request, int|string $postType): JsonResponse
    {
        try {
            $request->validate([
                'taxonomy_term_ids' => ['nullable'],
            ]);

            $postTypeData = $this->service->resolvePostType($postType);

            if (!$postTypeData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $this->service->ensureDefaultSteps($postTypeData);

            $termIds = $this->service->normalizeIds($request->taxonomy_term_ids);

            $steps = $this->service->activeSteps($postTypeData);

            $customFields = $this->service->formattedCustomFields(
                $postTypeData,
                $termIds,
                !empty($termIds)
            );

            $customFieldsById = $customFields->keyBy('id');

            $mappedRows = $this->service->mappedFieldRows($postTypeData);

            $mappedRowsByStepId = $mappedRows->groupBy('dynamic_post_form_step_id');

            $stepsPayload = $steps->map(function (DynamicPostFormStep $step) use ($mappedRowsByStepId, $customFieldsById) {
                $stepMappings = $mappedRowsByStepId->get($step->id, collect());

                $stepCustomFields = $stepMappings
                    ->sortBy('sort_order')
                    ->map(fn($mapping) => $customFieldsById->get((int) $mapping->custom_field_id))
                    ->filter()
                    ->values();

                return [
                    'id' => (int) $step->id,
                    'step_key' => $step->step_key,
                    'step_label' => $step->step_label,
                    'description' => $step->description,
                    'sort_order' => (int) $step->sort_order,

                    'base_fields' => $this->service->baseFieldsForStep($step->step_key),

                    'custom_fields_count' => $stepCustomFields->count(),
                    'custom_fields' => $stepCustomFields,
                ];
            })->values();

            return response()->json([
                'status' => true,
                'message' => 'Frontend step form fetched successfully.',
                'data' => [
                    'post_type' => $this->service->postTypePayload($postTypeData),
                    'taxonomy_term_ids' => $termIds,
                    'steps' => $stepsPayload,
                    'submit_api' => 'dynamic-posts',
                    'submit_method' => 'POST',
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch frontend step form.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
