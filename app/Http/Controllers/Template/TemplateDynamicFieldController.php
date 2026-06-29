<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\PostType;
use App\PageBuilder\Contracts\DynamicResolverInterface;
use App\PageBuilder\Foundation\WidgetManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class TemplateDynamicFieldController extends Controller
{
    public function __construct(
        protected WidgetManager $widgetManager,
        protected DynamicResolverInterface $dynamicResolver
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'post_type' => ['nullable', 'string'],
            'taxonomy_id' => ['nullable', 'integer'],
            'taxonomy_term_ids' => ['nullable', 'array'],
            'taxonomy_term_ids.*' => ['integer'],
        ]);

        $validator->after(function ($validator) use ($payload) {
            if (empty($payload['post_type_id']) && empty($payload['post_type'])) {
                $validator->errors()->add(
                    'post_type',
                    'Post type id or post type slug is required.'
                );
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $postType = $this->getPostTypeRecord($payload);

        if (! $postType) {
            return response()->json([
                'status' => false,
                'message' => 'Post type not found or inactive.',
            ], 404);
        }

        $widgets = $this->widgetManager->toApiArray();

        $dynamicFields = $this->dynamicResolver->availableFields(
            (int) $postType->id,
            $payload
        );

        return response()->json([
            'status' => true,
            'message' => 'Builder fields fetched successfully.',
            'data' => [
                'post_type_id' => $postType->id,
                'post_type' => $postType->slug,
                'post_type_name' => $postType->name,

                'widgets' => $widgets,

                /*
                 * Backward compatible for old frontend.
                 */
                'basic_widgets' => $this->formatWidgetsForOldBuilder($widgets),

                /*
                 * New full dynamic fields structure.
                 */
                'dynamic_fields' => $dynamicFields,

                /*
                 * Backward compatible for old frontend.
                 */
                'dynamic_custom_fields' => $this->formatDynamicFieldsForOldBuilder($dynamicFields),

                /*
                 * Combined drag items for builder.
                 */
                'builder_items' => array_merge(
                    $this->formatWidgetsForOldBuilder($widgets),
                    $this->formatDynamicFieldsForOldBuilder($dynamicFields)
                ),
            ],
        ]);
    }

    private function getPostTypeRecord(array $payload): ?PostType
    {
        $query = PostType::query();

        if (! empty($payload['post_type_id'])) {
            $query->where('id', $payload['post_type_id']);
        } elseif (! empty($payload['post_type'])) {
            $query->where('slug', $payload['post_type']);
        }

        $postType = $query->first();

        if (! $postType) {
            return null;
        }

        if (
            Schema::hasColumn($postType->getTable(), 'status')
            && ! in_array($postType->status, [true, 1, '1', 'active'], true)
        ) {
            return null;
        }

        return $postType;
    }

    private function formatWidgetsForOldBuilder(array $widgets): array
    {
        return collect($widgets)
            ->map(function (array $widget) {
                return [
                    'label' => $widget['name'],
                    'key' => $widget['type'],
                    'source' => 'widget',
                    'type' => $widget['type'],
                    'component_key' => $widget['type'],
                    'icon' => $widget['icon'] ?? null,
                    'category' => $widget['category'] ?? 'Basic',
                    'settings' => $widget['default_settings'] ?? [],
                    'schema' => $widget['schema'] ?? [],
                    'binding' => null,
                ];
            })
            ->values()
            ->all();
    }

    private function formatDynamicFieldsForOldBuilder(array $dynamicFields): array
    {
        $items = [];

        foreach (['system', 'custom', 'repeaters', 'taxonomies', 'relationships'] as $group) {
            foreach (($dynamicFields[$group] ?? []) as $field) {
                if (empty($field['key'])) {
                    continue;
                }

                $fieldType = (string) ($field['type'] ?? 'text');

                $items[] = [
                    'id' => $field['id'] ?? null,
                    'label' => $field['label'] ?? $field['key'],
                    'key' => $field['key'],
                    'source' => $field['source'] ?? $group,
                    'type' => $fieldType,

                    /*
                     * Important:
                     * This now maps to our PageBuilder widgets.
                     */
                    'component_key' => $this->mapFieldTypeToWidgetType($fieldType),

                    'settings' => [
                        'source' => 'dynamic',
                        'field' => $field['key'],
                    ],

                    'binding' => [
                        'source' => $field['source'] ?? $group,
                        'field_key' => $field['key'],
                        'field_slug' => $field['slug'] ?? null,
                    ],

                    'meta' => [
                        'required' => $field['required'] ?? false,
                        'options' => $field['options'] ?? [],
                        'group' => $field['group'] ?? null,
                        'sub_fields' => $field['sub_fields'] ?? [],
                        'terms' => $field['terms'] ?? [],
                    ],
                ];
            }
        }

        return $items;
    }

    private function mapFieldTypeToWidgetType(string $fieldType): string
    {
        return match ($fieldType) {
            'textarea', 'texteditor', 'editor', 'wysiwyg', 'richtext' => 'text',
            'image', 'media', 'file', 'featured_image' => 'image',
            'gallery', 'images' => 'gallery',
            'repeater', 'array', 'json' => 'repeater',
            'taxonomy', 'terms', 'taxonomy_terms' => 'taxonomy_terms',
            'html', 'custom_html', 'code' => 'html',
            'url', 'link' => 'button',
            default => 'text',
        };
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
