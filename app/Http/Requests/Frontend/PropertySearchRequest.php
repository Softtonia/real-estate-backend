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
            'location',
            'area_locality',
            'search',
            'sort_by',
        ] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        foreach ([
            'city_id',
            'state_id',
            'country_id',
        ] as $idKey) {
            if (isset($data[$idKey])) {
                if (!is_numeric($data[$idKey]) || (int) $data[$idKey] <= 0) {
                    unset($data[$idKey]);
                } else {
                    $data[$idKey] = (int) $data[$idKey];
                }
            }
        }

        if (isset($data['purpose'])) {
            if (is_string($data['purpose'])) {
                $pNorm = mb_strtolower(trim($data['purpose']));
                if (in_array($pNorm, ['buy', 'sell', 'sale', 'for-sale', 'purchase'], true)) {
                    $data['purpose'] = 'sell';
                } elseif (in_array($pNorm, ['rent', 'rental', 'lease', 'for-rent'], true)) {
                    $data['purpose'] = 'rent';
                }
            }
        }

        if (!isset($data['purpose']) && isset($data['tab'])) {
            $tab = is_string($data['tab']) ? mb_strtolower(trim($data['tab'])) : '';
            if (in_array($tab, ['buy', 'sell', 'sale'], true)) {
                $data['purpose'] = 'sell';
            } elseif (in_array($tab, ['rent', 'rental', 'lease'], true)) {
                $data['purpose'] = 'rent';
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

        foreach ([
            'is_sponsored',
            'sponsored',
            'is_featured',
            'featured',
            'show_on_home',
            'show_on_search',
            'show_on_detail',
        ] as $boolKey) {
            if (array_key_exists($boolKey, $data) && $data[$boolKey] !== null) {
                $val = $data[$boolKey];
                if (in_array($val, [1, '1', true, 'true', 'yes'], true)) {
                    $data[$boolKey] = true;
                } elseif (in_array($val, [0, '0', false, 'false', 'no'], true)) {
                    $data[$boolKey] = false;
                }
            }
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

            'location' => [
                'nullable',
                'string',
                'max:255',
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
            'post_type' => [
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

            'is_sponsored' => [
                'nullable',
                'boolean',
            ],
            'sponsored' => [
                'nullable',
                'boolean',
            ],
            'is_featured' => [
                'nullable',
                'boolean',
            ],
            'featured' => [
                'nullable',
                'boolean',
            ],
            'promotion_type' => [
                'nullable',
                'string',
                Rule::in(['featured', 'sponsored']),
            ],
            'placement' => [
                'nullable',
                'string',
                Rule::in(['home', 'search', 'detail']),
            ],
            'show_on_home' => [
                'nullable',
                'boolean',
            ],
            'show_on_search' => [
                'nullable',
                'boolean',
            ],
            'show_on_detail' => [
                'nullable',
                'boolean',
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
