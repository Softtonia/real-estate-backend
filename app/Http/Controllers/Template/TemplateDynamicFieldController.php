<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TemplateDynamicFieldController extends Controller
{
    public function index(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'post_type_id' => 'nullable|integer|exists:post_types,id',
            'post_type' => 'nullable|string',
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

        $postTypeRecord = $this->getPostTypeRecord($payload);

        if (!$postTypeRecord) {
            return response()->json([
                'status' => false,
                'message' => 'Post type not found or inactive.',
            ], 404);
        }

        $basicWidgets = $this->getBasicWidgets();
        $dynamicCustomFields = $this->getDynamicCustomFields($postTypeRecord);

        return response()->json([
            'status' => true,
            'message' => 'Builder fields fetched successfully.',
            'data' => [
                'post_type_id' => $postTypeRecord->id,
                'post_type' => $postTypeRecord->slug,
                'post_type_name' => $postTypeRecord->name,

                // fixed widgets for all post types
                'basic_widgets' => $basicWidgets,

                // selected post type ke custom fields
                'dynamic_custom_fields' => $dynamicCustomFields,

                // optional combined list for frontend builder
                'builder_items' => array_merge($basicWidgets, $dynamicCustomFields),
            ],
        ]);
    }

    private function getPostTypeRecord(array $payload)
    {
        if (!DB::getSchemaBuilder()->hasTable('post_types')) {
            return null;
        }

        $query = DB::table('post_types');

        if (!empty($payload['post_type_id'])) {
            $query->where('id', $payload['post_type_id']);
        } elseif (!empty($payload['post_type'])) {
            $query->where('slug', $payload['post_type']);
        }

        if (DB::getSchemaBuilder()->hasColumn('post_types', 'status')) {
            $query->where('status', true);
        }

        return $query->first();
    }

    private function getBasicWidgets(): array
    {
        return [
            [
                'label' => 'Title Widget',
                'key' => 'title_widget',
                'source' => 'basic_widget',
                'type' => 'title',
                'component_key' => 'title_widget',
                'settings' => [
                    'text' => '',
                    'tag' => 'h2',
                    'alignment' => 'left',
                ],
            ],
            [
                'label' => 'Text Editor',
                'key' => 'text_editor',
                'source' => 'basic_widget',
                'type' => 'editor',
                'component_key' => 'text_editor',
                'settings' => [
                    'content' => '',
                ],
            ],
            [
                'label' => 'Button',
                'key' => 'button',
                'source' => 'basic_widget',
                'type' => 'button',
                'component_key' => 'button',
                'settings' => [
                    'text' => 'Click Here',
                    'url' => '',
                    'target' => '_self',
                ],
            ],
            [
                'label' => 'Radio',
                'key' => 'radio',
                'source' => 'basic_widget',
                'type' => 'radio',
                'component_key' => 'radio',
                'settings' => [
                    'label' => '',
                    'options' => [],
                    'selected' => null,
                ],
            ],
            [
                'label' => 'Image',
                'key' => 'image',
                'source' => 'basic_widget',
                'type' => 'image',
                'component_key' => 'image',
                'settings' => [
                    'url' => '',
                    'alt' => '',
                ],
            ],
        ];
    }

    private function getDynamicCustomFields($postTypeRecord): array
    {
        if (!DB::getSchemaBuilder()->hasTable('custom_fields')) {
            return [];
        }

        $query = DB::table('custom_fields');

        /*
         * Agar custom_fields table me post_type_id column hai,
         * to ID se filter karega.
         */
        if (DB::getSchemaBuilder()->hasColumn('custom_fields', 'post_type_id')) {
            $query->where('post_type_id', $postTypeRecord->id);
        }

        /*
         * Agar custom_fields table me post_type column hai,
         * to slug se filter karega.
         */
        elseif (DB::getSchemaBuilder()->hasColumn('custom_fields', 'post_type')) {
            $query->where('post_type', $postTypeRecord->slug);
        }

        /*
         * Agar custom_fields table me post_type_slug column hai,
         * to slug se filter karega.
         */
        elseif (DB::getSchemaBuilder()->hasColumn('custom_fields', 'post_type_slug')) {
            $query->where('post_type_slug', $postTypeRecord->slug);
        }

        /*
         * Agar status column hai to active fields hi laayega.
         */
        if (DB::getSchemaBuilder()->hasColumn('custom_fields', 'status')) {
            $query->where('status', true);
        }

        if (DB::getSchemaBuilder()->hasColumn('custom_fields', 'sort_order')) {
            $query->orderBy('sort_order');
        } else {
            $query->orderBy('id');
        }

        return $query->get()
            ->map(function ($field) {
                $label = $field->field_label
                    ?? $field->label
                    ?? $field->name
                    ?? 'Custom Field';

                $key = $field->field_name_slug
                    ?? $field->field_name
                    ?? $field->slug
                    ?? $field->name
                    ?? null;

                $type = $field->field_type
                    ?? $field->type
                    ?? 'text';

                return [
                    'id' => $field->id ?? null,
                    'label' => $label,
                    'key' => $key,
                    'source' => 'custom_field',
                    'type' => $type,
                    'component_key' => $this->mapFieldTypeToComponent($type),

                    /*
                     * Builder binding ke liye important.
                     * Isse frontend ko pata chalega ki ye field dynamic data se bind hoga.
                     */
                    'binding' => [
                        'source' => 'custom_field',
                        'field_id' => $field->id ?? null,
                        'field_key' => $key,
                    ],

                    'meta' => [
                        'required' => (bool) ($field->required ?? false),
                        'placeholder' => $field->field_placeholder ?? null,
                        'options' => $this->decodeMaybeJson($field->options ?? null),
                        'media_limit' => $field->media_limit ?? null,
                        'media_size' => $field->media_size ?? null,
                        'media_format' => $field->media_format ?? null,
                        'repeater' => $field->repeater ?? null,
                    ],
                ];
            })
            ->filter(function ($field) {
                return !empty($field['key']);
            })
            ->values()
            ->toArray();
    }

    private function mapFieldTypeToComponent(string $type): string
    {
        return match ($type) {
            'textarea', 'editor', 'richtext' => 'dynamic_text_editor',
            'number', 'price' => 'dynamic_number',
            'image', 'file' => 'dynamic_image',
            'gallery', 'media' => 'dynamic_gallery',
            'radio' => 'dynamic_radio',
            'select', 'dropdown' => 'dynamic_select',
            'checkbox' => 'dynamic_checkbox',
            'map', 'location' => 'dynamic_map',
            default => 'dynamic_text',
        };
    }

    private function decodeMaybeJson($value)
    {
        if ($value === null || $value === '') {
            return null;
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

            return $value;
        }

        return $value;
    }

    private function getPayload(Request $request): array
    {
        $payload = $request->all();

        if (empty($payload)) {
            $payload = $request->json()->all();
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