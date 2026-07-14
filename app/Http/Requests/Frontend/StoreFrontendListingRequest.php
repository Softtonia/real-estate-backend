<?php

namespace App\Http\Requests\Frontend;

use Illuminate\Foundation\Http\FormRequest;

class StoreFrontendListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'post_type_id' => [
                'required',
                'integer',
                'exists:post_types,id',
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:255',
            ],

            'excerpt' => [
                'nullable',
                'string',
            ],

            'content' => [
                'nullable',
                'string',
            ],

            /*
             * Expected format:
             *
             * taxonomies: {
             *     "property-type": [1],
             *     "purpose": [5],
             *     "property-status": [8]
             * }
             */

            'taxonomies' => [
                'required',
                'array',
            ],

            'taxonomies.property-type' => [
                'required',
                'array',
                'min:1',
            ],

            'taxonomies.property-type.*' => [
                'required',
                'integer',
                'exists:taxonomy_terms,id',
            ],

            'taxonomies.purpose' => [
                'required',
                'array',
                'min:1',
            ],

            'taxonomies.purpose.*' => [
                'required',
                'integer',
                'exists:taxonomy_terms,id',
            ],

            'taxonomies.property-status' => [
                'required',
                'array',
                'min:1',
            ],

            'taxonomies.property-status.*' => [
                'required',
                'integer',
                'exists:taxonomy_terms,id',
            ],

            /*
             * Optional custom fields.
             */

            'custom_fields' => [
                'nullable',
                'array',
            ],

            'featured_image_id' => [
                'nullable',
                'integer',
            ],

            'gallery_image_ids' => [
                'nullable',
                'array',
            ],

            'gallery_image_ids.*' => [
                'integer',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'taxonomies.property-type.required' => 'Please select a property type.',
            'taxonomies.purpose.required' => 'Please select the property purpose.',
            'taxonomies.property-status.required' => 'Please select the property status.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $taxonomies = $this->input('taxonomies', []);

        if (is_string($taxonomies)) {
            $decoded = json_decode($taxonomies, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([
                    'taxonomies' => $decoded,
                ]);
            }
        }

        $galleryImageIds = $this->input('gallery_image_ids');

        if (is_string($galleryImageIds)) {
            $decoded = json_decode($galleryImageIds, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([
                    'gallery_image_ids' => $decoded,
                ]);
            }
        }

        $customFields = $this->input('custom_fields');

        if (is_string($customFields)) {
            $decoded = json_decode($customFields, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([
                    'custom_fields' => $decoded,
                ]);
            }
        }
    }
}