<?php

namespace App\Http\Requests\Frontend;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropertySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach ([
            'area_locality',
            'search',
            'sort_by',
        ] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        foreach ([
            'purpose',
            'property_type',
            'bedrooms',
            'taxonomy_term_ids',
        ] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $data[$key] = $this->normalizeFlexibleFilterValue($data[$key]);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $flexibleFilter = function (
            string $attribute,
            mixed $value,
            \Closure $fail
        ): void {
            if ($value === null || $value === '') {
                return;
            }

            $values = is_array($value) ? $value : [$value];

            foreach ($values as $item) {
                if (is_array($item)) {
                    $item = $item['id']
                        ?? $item['value']
                        ?? $item['slug']
                        ?? $item['name']
                        ?? null;
                }

                if (
                    $item !== null
                    && !is_string($item)
                    && !is_int($item)
                    && !is_float($item)
                ) {
                    $fail(
                        "{$attribute} must contain only ids, slugs, names or scalar values."
                    );

                    return;
                }
            }
        };

        return [
            'country_id' => [
                'nullable',
                'integer',
                'exists:countries,id',
            ],
            'state_id' => [
                'nullable',
                'integer',
                'exists:states,id',
            ],
            'city_id' => [
                'nullable',
                'integer',
                'exists:cities,id',
            ],

            'area_locality' => [
                'nullable',
                'string',
                'max:255',
            ],
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            // Accepts id, slug, name, comma-separated values, JSON array or array.
            'purpose' => [
                'nullable',
                $flexibleFilter,
            ],
            'property_type' => [
                'nullable',
                $flexibleFilter,
            ],
            'bedrooms' => [
                'nullable',
                $flexibleFilter,
            ],
            'taxonomy_term_ids' => [
                'nullable',
                $flexibleFilter,
            ],

            'price_min' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'price_max' => [
                'nullable',
                'numeric',
                'gte:price_min',
            ],

            'sort_by' => [
                'nullable',
                Rule::in([
                    'newest',
                    'oldest',
                    'price_low',
                    'price_high',
                    'relevance',
                ]),
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ];
    }

    private function normalizeFlexibleFilterValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (
            json_last_error() === JSON_ERROR_NONE
            && is_array($decoded)
        ) {
            return $decoded;
        }

        if (str_contains($value, ',')) {
            return array_values(array_filter(
                array_map('trim', explode(',', $value)),
                fn ($item) => $item !== ''
            ));
        }

        return $value;
    }
}
