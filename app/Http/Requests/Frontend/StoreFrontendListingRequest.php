<?php

namespace App\Http\Requests\Frontend;

use Illuminate\Foundation\Http\FormRequest;

class StoreFrontendListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        foreach (
            [
                'taxonomies',
                'gallery_image_ids',
                'custom_fields',
            ] as $field
        ) {
            $value = $this->input($field);

            if (!is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
            ) {
                $this->merge([
                    $field => $decoded,
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
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
             * Payload:
             *
             * "taxonomies": [
             *     {
             *         "taxonomy_id": 1,
             *         "taxonomy_term_id": 10
             *     },
             *     {
             *         "taxonomy_id": 2,
             *         "taxonomy_term_id": 20
             *     }
             * ]
             */

            'taxonomies' => [
                'required',
                'array',
                'min:1',
            ],

            'taxonomies.*.taxonomy_id' => [
                'required',
                'integer',
                'exists:taxonomies,id',
            ],

            'taxonomies.*.taxonomy_term_id' => [
                'required_without:taxonomies.*.taxonomy_term_ids',
                'nullable',
                'integer',
                'exists:taxonomy_terms,id',
            ],

            'taxonomies.*.taxonomy_term_ids' => [
                'required_without:taxonomies.*.taxonomy_term_id',
                'nullable',
                'array',
                'min:1',
            ],

            'taxonomies.*.taxonomy_term_ids.*' => [
                'required',
                'integer',
                'exists:taxonomy_terms,id',
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

            'custom_fields' => [
                'nullable',
                'array',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'taxonomies.required' => 'Please select listing taxonomies.',
            'taxonomies.*.taxonomy_id.required' => 'Taxonomy ID is required.',
            'taxonomies.*.taxonomy_term_id.required_without' => 'Please select a taxonomy term.',
            'taxonomies.*.taxonomy_term_ids.required_without' => 'Please select at least one taxonomy term.',
        ];
    }
}