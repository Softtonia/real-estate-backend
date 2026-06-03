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

        $validator = Validator::make($payload, [
            'post_type' => 'required|in:property-listing,project-listing,developer-listing',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $postType = $payload['post_type'];

        $systemFields = $this->getSystemFields($postType);
        $customFields = $this->getCustomFields($postType);

        return response()->json([
            'status' => true,
            'message' => 'Dynamic fields fetched successfully.',
            'data' => [
                'post_type' => $postType,
                'system_fields' => $systemFields,
                'custom_fields' => $customFields,
                'all_fields' => array_merge($systemFields, $customFields),
            ],
        ]);
    }

    private function getSystemFields(string $postType): array
    {
        $common = [
            [
                'label' => 'Title',
                'key' => 'title',
                'source' => 'system',
                'type' => 'text',
                'component_key' => 'dynamic_text',
            ],
            [
                'label' => 'Description',
                'key' => 'description',
                'source' => 'system',
                'type' => 'textarea',
                'component_key' => 'dynamic_description',
            ],
            [
                'label' => 'Featured Image',
                'key' => 'featured_image',
                'source' => 'system',
                'type' => 'image',
                'component_key' => 'dynamic_image',
            ],
            [
                'label' => 'Gallery',
                'key' => 'gallery',
                'source' => 'system',
                'type' => 'gallery',
                'component_key' => 'dynamic_gallery',
            ],
        ];

        if ($postType === 'property-listing') {
            return array_merge($common, [
                [
                    'label' => 'Price',
                    'key' => 'price',
                    'source' => 'system',
                    'type' => 'number',
                    'component_key' => 'dynamic_price',
                ],
                [
                    'label' => 'Purpose',
                    'key' => 'purpose',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
                [
                    'label' => 'Property Type',
                    'key' => 'property_type',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
                [
                    'label' => 'Property Status',
                    'key' => 'property_status',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
                [
                    'label' => 'Location',
                    'key' => 'location',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
            ]);
        }

        if ($postType === 'project-listing') {
            return array_merge($common, [
                [
                    'label' => 'Project Status',
                    'key' => 'project_status',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
                [
                    'label' => 'Developer',
                    'key' => 'developer',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
                [
                    'label' => 'Location',
                    'key' => 'location',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
            ]);
        }

        if ($postType === 'developer-listing') {
            return array_merge($common, [
                [
                    'label' => 'Developer Name',
                    'key' => 'developer_name',
                    'source' => 'system',
                    'type' => 'text',
                    'component_key' => 'dynamic_text',
                ],
                [
                    'label' => 'Logo',
                    'key' => 'logo',
                    'source' => 'system',
                    'type' => 'image',
                    'component_key' => 'dynamic_image',
                ],
            ]);
        }

        return $common;
    }

    private function getCustomFields(string $postType): array
    {
        if (!DB::getSchemaBuilder()->hasTable('custom_fields')) {
            return [];
        }

        $query = DB::table('custom_fields');

        if (DB::getSchemaBuilder()->hasColumn('custom_fields', 'post_type')) {
            $query->where('post_type', $postType);
        }

        return $query->get()->map(function ($field) {
            $label = $field->field_label ?? $field->label ?? $field->name ?? 'Custom Field';
            $key = $field->field_name_slug ?? $field->field_name ?? $field->slug ?? $field->name ?? null;
            $type = $field->field_type ?? $field->type ?? 'text';

            return [
                'id' => $field->id ?? null,
                'label' => $label,
                'key' => $key,
                'source' => 'custom_field',
                'type' => $type,
                'required' => (bool) ($field->required ?? false),
                'component_key' => $this->mapFieldTypeToComponent($type),
                'meta' => [
                    'placeholder' => $field->field_placeholder ?? null,
                    'options' => $field->options ?? null,
                    'media_limit' => $field->media_limit ?? null,
                    'media_size' => $field->media_size ?? null,
                    'media_format' => $field->media_format ?? null,
                    'repeater' => $field->repeater ?? null,
                ],
            ];
        })->filter(function ($field) {
            return !empty($field['key']);
        })->values()->toArray();
    }

    private function mapFieldTypeToComponent(string $type): string
    {
        return match ($type) {
            'textarea', 'editor', 'richtext' => 'dynamic_description',
            'number', 'price' => 'dynamic_price',
            'image', 'file' => 'dynamic_image',
            'gallery', 'media' => 'dynamic_gallery',
            'map', 'location' => 'dynamic_map',
            default => 'dynamic_text',
        };
    }
}
