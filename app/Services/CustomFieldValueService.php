<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\CustomFieldValue;

class CustomFieldValueService
{
    /**
     * Field types that store JSON data.
     * Repeater removed because repeater values are stored separately
     * in custom_field_repeater_values table.
     */
    private const JSON_FIELD_TYPES = ['checkbox', 'media', 'file'];

    /**
     * Main entry point: Save custom field values for an entity.
     *
     * @param int    $entityId
     * @param string $entityType post|taxonomy_term|user
     * @param array  $fields
     */
    public function saveValues(int $entityId, string $entityType, array $fields): void
    {
        foreach ($fields as $field) {
            if (empty($field['custom_field_id'])) {
                continue;
            }

            $customField = CustomField::find($field['custom_field_id']);

            if (!$customField) {
                continue;
            }

            /*
             |--------------------------------------------------------------------------
             | Repeater Field
             |--------------------------------------------------------------------------
             |
             | Repeater values should NOT be stored in custom_field_values.value_json.
             | Only parent field record is created here.
             | Actual repeater values are saved separately in:
             | custom_field_repeater_values
             |
             */
            if ($customField->field_type === 'repeater') {
                CustomFieldValue::updateOrCreate(
                    [
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'custom_field_id' => $customField->id,
                    ],
                    [
                        'custom_field_option_id' => null,
                        'value_text' => null,
                        'value_string' => null,
                        'value_number' => null,
                        'value_date' => null,
                        'value_datetime' => null,
                        'value_json' => null,
                    ]
                );

                continue;
            }

            $resolved = $this->resolveValue($customField, $field);

            CustomFieldValue::updateOrCreate(
                [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'custom_field_id' => $customField->id,
                ],
                $resolved
            );
        }
    }

    /**
     * Resolve the correct columns for a given field type and raw input.
     */
    private function resolveValue(CustomField $customField, array $field): array
    {
        $fieldType = $customField->field_type;
        $rawValue = $this->resolveLegacyValue($field, $customField);

        $record = [
            'custom_field_option_id' => null,
            'value_text' => null,
            'value_string' => null,
            'value_number' => null,
            'value_date' => null,
            'value_datetime' => null,
            'value_json' => null,
        ];

        if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
            return $record;
        }

        if (is_array($rawValue) && !in_array($fieldType, ['checkbox', 'media', 'file'], true)) {
            $record['value_json'] = $rawValue;
            return $record;
        }

        switch ($fieldType) {
            case 'text':
            case 'texteditor':
            case 'textarea':
                $record['value_text'] = is_array($rawValue)
                    ? json_encode($rawValue, JSON_UNESCAPED_SLASHES)
                    : (string) $rawValue;
                break;

            case 'email':
            case 'url':
                $record['value_string'] = is_array($rawValue)
                    ? json_encode($rawValue, JSON_UNESCAPED_SLASHES)
                    : (string) $rawValue;
                break;

            case 'number':
                $record['value_json'] = $rawValue;
                $record['value_number'] = is_numeric($rawValue) ? $rawValue : null;
                break;

            case 'boolean':
                $record['value_string'] = $this->normalizeBoolean($rawValue) ? '1' : '0';
                $record['value_json'] = $this->normalizeBoolean($rawValue);
                break;

            case 'date':
                $record['value_date'] = $this->normalizeDate($rawValue);
                $record['value_string'] = $record['value_date'];
                break;

            case 'datetime':
                $record['value_datetime'] = $this->normalizeDatetime($rawValue);
                $record['value_string'] = $record['value_datetime'];
                break;

            case 'select':
            case 'radio':
                $record = $this->resolveOptionValue($customField, $rawValue, $record);
                break;

            case 'checkbox':
                $record = $this->resolveCheckboxValue($customField, $rawValue, $record);
                break;

            case 'media':
            case 'file':
                $record['value_json'] = $this->normalizeMediaValue($rawValue);
                if (is_array($record['value_json']) && !empty($record['value_json'])) {
                    $firstMedia = $record['value_json'][0] ?? null;
                    $firstUrl = is_array($firstMedia) ? ($firstMedia['url'] ?? $firstMedia['path'] ?? null) : (string) $firstMedia;
                    $record['value_string'] = $firstUrl;
                    $record['value_text'] = $firstUrl;
                }
                break;

            default:
                $record['value_text'] = is_array($rawValue)
                    ? json_encode($rawValue, JSON_UNESCAPED_SLASHES)
                    : (string) $rawValue;
                break;
        }

        if (in_array($fieldType, self::JSON_FIELD_TYPES, true) && $record['value_json'] === null) {
            $record['value_json'] = is_array($rawValue)
                ? $rawValue
                : (
                    is_string($rawValue) && json_decode($rawValue, true)
                        ? json_decode($rawValue, true)
                        : $rawValue
                );
        }

        return $record;
    }

    /**
     * Resolve old and new payload keys.
     */
    private function resolveLegacyValue(array $field, ?CustomField $customField = null): mixed
    {
        $fieldType = $customField?->field_type;

        // If this is a JSON-based field type (media, file, checkbox), prioritize value_json
        if ($fieldType && in_array($fieldType, self::JSON_FIELD_TYPES, true)) {
            if (isset($field['value_json']) && $field['value_json'] !== '' && $field['value_json'] !== null) {
                return $field['value_json'];
            }
            if (isset($field['media']) && $field['media'] !== '' && $field['media'] !== null) {
                return $field['media'];
            }
            if (isset($field['media_files']) && $field['media_files'] !== '' && $field['media_files'] !== null) {
                return $field['media_files'];
            }
            if (isset($field['files']) && $field['files'] !== '' && $field['files'] !== null) {
                return $field['files'];
            }
            if (isset($field['file']) && $field['file'] !== '' && $field['file'] !== null) {
                return $field['file'];
            }
            if (isset($field['value']) && $field['value'] !== '' && $field['value'] !== null) {
                return $field['value'];
            }
        }

        // Check non-empty keys in priority order
        foreach (['value', 'value_json', 'value_text', 'value_string', 'value_number', 'value_date', 'value_datetime'] as $key) {
            if (isset($field[$key]) && $field[$key] !== '' && $field[$key] !== null) {
                return $field[$key];
            }
        }

        return $field['value']
            ?? $field['value_json']
            ?? $field['value_text']
            ?? $field['value_string']
            ?? $field['value_number']
            ?? $field['value_date']
            ?? $field['value_datetime']
            ?? null;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        return false;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizeDatetime(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function resolveOptionValue(CustomField $customField, mixed $rawValue, array $record): array
    {
        if (empty($rawValue)) {
            return $record;
        }

        $options = $customField->options ?? collect();

        $option = null;

        if (is_numeric($rawValue)) {
            $option = $options->firstWhere('id', (int) $rawValue);
        }

        if (!$option) {
            $option = $options->firstWhere('value', $rawValue);
        }

        if (!$option) {
            $option = $options->firstWhere('name', $rawValue);
        }

        if ($option) {
            $record['custom_field_option_id'] = $option->id;
            $record['value_string'] = $option->value ?? $option->name;
        } else {
            $record['value_string'] = is_array($rawValue) ? json_encode($rawValue, JSON_UNESCAPED_SLASHES) : (string) $rawValue;
        }

        return $record;
    }

    private function resolveCheckboxValue(CustomField $customField, mixed $rawValue, array $record): array
    {
        if (empty($rawValue)) {
            return $record;
        }

        if (is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);
            $values = is_array($decoded) ? $decoded : [$rawValue];
        } elseif (is_array($rawValue)) {
            $values = $rawValue;
        } else {
            $values = [$rawValue];
        }

        $options = $customField->options ?? collect();
        $optionIds = [];
        $optionValues = [];

        foreach ($values as $val) {
            $opt = null;

            if (is_numeric($val)) {
                $opt = $options->firstWhere('id', (int) $val);
            }

            if (!$opt) {
                $opt = $options->firstWhere('value', $val);
            }

            if (!$opt) {
                $opt = $options->firstWhere('name', $val);
            }

            if ($opt) {
                $optionIds[] = $opt->id;
                $optionValues[] = $opt->value ?? $opt->name;
            } else {
                $optionValues[] = (string) $val;
            }
        }

        $record['value_json'] = $optionValues;
        $record['value_text'] = json_encode($optionValues, JSON_UNESCAPED_SLASHES);
        $record['value_string'] = implode(', ', $optionValues);

        if (!empty($optionIds)) {
            $record['custom_field_option_id'] = $optionIds[0];
        }

        return $record;
    }

    private function normalizeMediaValue(mixed $rawValue): ?array
    {
        if (empty($rawValue)) {
            return null;
        }

        if (is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $rawValue = $decoded;
            } else {
                $url = trim($rawValue);
                return [[
                    'id' => null,
                    'url' => $url,
                    'is_featured' => false,
                ]];
            }
        }

        if (is_array($rawValue)) {
            // Support wrapper structure: { images: [...], featured: 123 }
            if (isset($rawValue['images']) && is_array($rawValue['images'])) {
                $featuredRef = $rawValue['featured'] ?? $rawValue['featured_id'] ?? $rawValue['featured_url'] ?? null;
                $normalizedImages = [];
                foreach ($rawValue['images'] as $idx => $img) {
                    $item = is_array($img) ? $img : (is_numeric($img) ? ['id' => (int) $img] : ['url' => (string) $img]);
                    $isFeatured = false;
                    if (isset($item['is_featured'])) {
                        $isFeatured = (bool) $item['is_featured'];
                    } elseif ($featuredRef !== null) {
                        $isFeatured = (isset($item['id']) && (int) $item['id'] === (int) $featuredRef)
                            || (isset($item['url']) && $item['url'] === (string) $featuredRef)
                            || ($featuredRef === $idx);
                    }
                    $item['is_featured'] = $isFeatured;
                    $normalizedImages[] = $item;
                }
                return $normalizedImages;
            }

            if (isset($rawValue['id']) || isset($rawValue['url']) || isset($rawValue['path'])) {
                $path = $rawValue['path'] ?? null;
                $url = $rawValue['url'] ?? null;
                if (!$url && $path) {
                    $url = \Illuminate\Support\Facades\Storage::disk($rawValue['disk'] ?? 'public')->url($path);
                }

                return [array_merge($rawValue, [
                    'id' => isset($rawValue['id']) ? (int) $rawValue['id'] : null,
                    'url' => $url,
                    'path' => $path,
                    'is_featured' => (bool) ($rawValue['is_featured'] ?? $rawValue['featured'] ?? false),
                ])];
            }

            if (isset($rawValue[0])) {
                return array_map(function ($item) {
                    if (is_string($item)) {
                        return [
                            'id' => null,
                            'url' => $item,
                            'is_featured' => false,
                        ];
                    }

                    if (is_array($item)) {
                        $path = $item['path'] ?? null;
                        $url = $item['url'] ?? null;
                        if (!$url && $path) {
                            $url = \Illuminate\Support\Facades\Storage::disk($item['disk'] ?? 'public')->url($path);
                        }

                        return array_merge($item, [
                            'id' => isset($item['id']) ? (int) $item['id'] : null,
                            'url' => $url,
                            'path' => $path,
                            'is_featured' => (bool) ($item['is_featured'] ?? $item['featured'] ?? false),
                        ]);
                    }

                    return $item;
                }, $rawValue);
            }
        }

        if (is_numeric($rawValue)) {
            return [['id' => (int) $rawValue, 'is_featured' => true]];
        }

        if (filter_var($rawValue, FILTER_VALIDATE_URL) || str_starts_with((string) $rawValue, 'http')) {
            return [['url' => (string) $rawValue, 'is_featured' => true]];
        }

        return [['url' => (string) $rawValue, 'is_featured' => true]];
    }

    public static function formatValue(CustomFieldValue $value): array
    {
        $customField = $value->customField;

        if (!$customField) {
            return [
                'custom_field_id' => $value->custom_field_id,
                'value' => $value->value,
                'field_type' => null,
            ];
        }

        $fieldType = $customField->field_type;
        $formattedValue = null;

        switch ($fieldType) {
            case 'boolean':
                $raw = $value->value_json ?? $value->value_string ?? $value->value;
                $formattedValue = is_bool($raw)
                    ? $raw
                    : in_array((string) $raw, ['1', 'true', 'yes'], true);
                break;

            case 'number':
                $formattedValue = $value->value_json ?? $value->value_number;

                if (is_numeric($formattedValue)) {
                    $formattedValue = str_contains((string) $formattedValue, '.')
                        ? (float) $formattedValue
                        : (int) $formattedValue;
                }
                break;

            case 'checkbox':
                $rawJson = $value->value_json;

                if (is_array($rawJson)) {
                    $formattedValue = $rawJson;
                } else {
                    $optionIds = array_filter(explode(',', $value->value_string ?? ''));
                    $formattedValue = array_map(
                        fn($id) => ['custom_field_option_id' => (int) $id],
                        $optionIds
                    );
                }
                break;

            case 'repeater':
                /*
                 |--------------------------------------------------------------------------
                 | Repeater values are stored separately
                 |--------------------------------------------------------------------------
                 |
                 | Actual repeater rows are not stored in custom_field_values.value_json.
                 | They should be fetched from custom_field_repeater_values table
                 | inside the controller response formatter.
                 |
                 */
                $formattedValue = null;
                break;

            case 'media':
            case 'file':
                $formattedValue = $value->value_json ?? [];
                break;

            case 'date':
                $formattedValue = $value->value_date ?? $value->value_string;
                break;

            case 'datetime':
                $formattedValue = $value->value_datetime ?? $value->value_string;
                break;

            case 'select':
            case 'radio':
                $formattedValue = $value->value_string;
                break;

            default:
                $formattedValue = $value->value;
                break;
        }

        return [
            'custom_field_id' => $customField->id,
            'field_label' => $customField->field_label,
            'field_name_slug' => $customField->field_name_slug,
            'field_type' => $fieldType,
            'field_placeholder' => $customField->field_placeholder,
            'value' => $formattedValue,
        ];
    }

    public static function validateFieldValue(CustomField $customField, mixed $value, bool $isRequired = false): ?string
    {
        if ($isRequired && (is_null($value) || $value === '' || $value === [])) {
            return 'This field is required.';
        }

        if (is_null($value) || $value === '') {
            return null;
        }

        switch ($customField->field_type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return 'Please provide a valid email address.';
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    return 'Please provide a valid URL.';
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return 'Please provide a valid number.';
                }
                break;

            case 'date':
                try {
                    \Carbon\Carbon::parse($value);
                } catch (\Exception $e) {
                    return 'Please provide a valid date.';
                }
                break;

            case 'datetime':
                try {
                    \Carbon\Carbon::parse($value);
                } catch (\Exception $e) {
                    return 'Please provide a valid date/time.';
                }
                break;
        }

        return null;
    }
}