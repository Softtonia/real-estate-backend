<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\CustomFieldRepeater;
use App\Models\CustomFieldRepeaterValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomFieldValueService
{
    /**
     * Field types that store options (select, radio, checkbox).
     */
    private const OPTION_FIELD_TYPES = ['select', 'radio', 'checkbox'];

    /**
     * Field types that store JSON data.
     */
    private const JSON_FIELD_TYPES = ['checkbox', 'repeater', 'media', 'file'];

    /**
     * Main entry point: Save custom field values for an entity.
     *
     * Accepts the same format whether creating or updating.
     * Each $field entry must have 'custom_field_id', and may have:
     *   - 'value' (unified value – replaces old column-specific keys)
     *   - Or legacy keys: value_text, value_string, value_number, value_date, value_datetime, value_json
     *
     * @param int    $entityId
     * @param string $entityType (post|taxonomy_term|user)
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

            // Resolve the value
            $resolved = $this->resolveValue($customField, $field);

            if ($customField->field_type === 'repeater') {
                $this->saveRepeaterValues($entityId, $entityType, $customField, $resolved['value_json'] ?? []);
                continue;
            }

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
        $rawValue = $field['value'] ?? $this->resolveLegacyValue($field);

        // Default empty record
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

        // Protect against "Array to string conversion" – if an array somehow reaches here,
        // route it to value_json rather than trying to cast to string.
        if (is_array($rawValue) && !in_array($fieldType, ['checkbox', 'media', 'file', 'repeater'], true)) {
            $record['value_json'] = $rawValue;
            return $record;
        }

        switch ($fieldType) {
            case 'text':
            case 'texteditor':
            case 'textarea':
                $record['value_text'] = is_array($rawValue) ? json_encode($rawValue) : (string) $rawValue;
                break;

            case 'email':
            case 'url':
                $record['value_string'] = is_array($rawValue) ? json_encode($rawValue) : (string) $rawValue;
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
                break;

            default:
                // Fallback: store as text – never cast array to string directly
                $record['value_text'] = is_array($rawValue) ? json_encode($rawValue) : (string) $rawValue;
                break;
        }

        // Ensure value_json is always set for JSON field types if not already
        if (in_array($fieldType, self::JSON_FIELD_TYPES, true) && $record['value_json'] === null) {
            $record['value_json'] = is_array($rawValue) ? $rawValue : (is_string($rawValue) && json_decode($rawValue) ? json_decode($rawValue, true) : $rawValue);
        }

        return $record;
    }

    /**
     * Resolve legacy value keys (value_text, value_string, etc.)
     */
    private function resolveLegacyValue(array $field): mixed
    {
        return $field['value_text']
            ?? $field['value_string']
            ?? $field['value_number']
            ?? $field['value_date']
            ?? $field['value_datetime']
            ?? $field['value_json']
            ?? null;
    }

    /**
     * Normalize boolean values to real true/false.
     */
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

    /**
     * Normalize date to Y-m-d format.
     */
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

    /**
     * Normalize datetime to Y-m-d H:i:s format.
     */
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

    /**
     * Resolve option value for select/radio fields.
     */
    private function resolveOptionValue(CustomField $customField, mixed $rawValue, array $record): array
    {
        // Try to match the raw value to an option
        $option = $customField->options()
            ->where(function ($q) use ($rawValue) {
                $q->where('value', (string) $rawValue)
                    ->orWhere('id', is_numeric($rawValue) ? (int) $rawValue : 0)
                    ->orWhere('name', (string) $rawValue);
            })
            ->first();

        if ($option) {
            $record['custom_field_option_id'] = $option->id;
            $record['value_string'] = $option->value;
        } else {
            $record['value_string'] = (string) $rawValue;
        }

        return $record;
    }

    /**
     * Resolve checkbox values (multiple selections → JSON array).
     */
    private function resolveCheckboxValue(CustomField $customField, mixed $rawValue, array $record): array
    {
        $selectedValues = [];

        // Accept array of values
        $values = is_array($rawValue) ? $rawValue : [(string) $rawValue];

        foreach ($values as $val) {
            $option = $customField->options()
                ->where(function ($q) use ($val) {
                    $q->where('value', (string) $val)
                        ->orWhere('id', is_numeric($val) ? (int) $val : 0)
                        ->orWhere('name', (string) $val);
                })
                ->first();

            if ($option) {
                $selectedValues[] = [
                    'custom_field_option_id' => $option->id,
                    'name' => $option->name,
                    'value' => $option->value,
                ];
            } else {
                $selectedValues[] = [
                    'custom_field_option_id' => null,
                    'name' => null,
                    'value' => (string) $val,
                ];
            }
        }

        $record['value_json'] = $selectedValues;

        // For backward compatibility: store comma-separated option IDs in value_string
        $optionIds = array_column(array_filter($selectedValues, fn($v) => $v['custom_field_option_id'] !== null), 'custom_field_option_id');
        $record['value_string'] = !empty($optionIds) ? implode(',', $optionIds) : null;

        return $record;
    }

    /**
     * Normalize media/file value to structured JSON.
     */
    private function normalizeMediaValue(mixed $rawValue): ?array
    {
        if (empty($rawValue)) {
            return null;
        }

        // If already an array with structure, use it
        if (is_array($rawValue)) {
            // Handle single media object
            if (isset($rawValue['id']) || isset($rawValue['url']) || isset($rawValue['path'])) {
                return $rawValue;
            }

            // Handle array of media objects
            if (isset($rawValue[0]) && is_array($rawValue[0])) {
                return $rawValue;
            }

            // Handle array of IDs
            return array_map(function ($item) {
                if (is_numeric($item)) {
                    return ['id' => (int) $item];
                }
                return ['url' => (string) $item];
            }, $rawValue);
        }

        // If it's a numeric string, store as ID
        if (is_numeric($rawValue)) {
            return [['id' => (int) $rawValue]];
        }

        // If it's a URL string
        if (filter_var($rawValue, FILTER_VALIDATE_URL) || str_starts_with((string) $rawValue, 'http')) {
            return [['url' => (string) $rawValue]];
        }

        // Fallback: store as-is
        return [['url' => (string) $rawValue]];
    }

    private function saveRepeaterValues(
        int $entityId,
        string $entityType,
        CustomField $customField,
        mixed $repeaterData
    ): void {
        if (is_string($repeaterData)) {
            $decoded = json_decode($repeaterData, true);
            $repeaterData = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($repeaterData)) {
            $repeaterData = [];
        }

        /*
     | Controller stores repeater like:
     | [
     |   'repeaters' => [
     |      ['rows' => [ ['floor_name' => 'vijay'] ]]
     |   ]
     | ]
     */
        if (isset($repeaterData['repeaters']) && is_array($repeaterData['repeaters'])) {
            $blocks = $repeaterData['repeaters'];
        } elseif (isset($repeaterData['rows']) && is_array($repeaterData['rows'])) {
            $blocks = [$repeaterData];
        } else {
            // Direct rows array fallback
            $blocks = [
                [
                    'custom_field_repeater_id' => null,
                    'field_name_slug' => null,
                    'rows' => $repeaterData,
                ],
            ];
        }

        $repeaterFields = $customField->activeRepeaters()
            ->get()
            ->keyBy('field_name_slug');

        $normalizedBlocks = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $rows = $block['rows'] ?? [];

            if (is_string($rows)) {
                $decodedRows = json_decode($rows, true);
                $rows = is_array($decodedRows) ? $decodedRows : [];
            }

            if (!is_array($rows)) {
                $rows = [];
            }

            $normalizedRows = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $normalizedRow = [];

                foreach ($row as $fieldSlug => $fieldValue) {
                    $repeaterField = $repeaterFields->get($fieldSlug);

                    if (!$repeaterField) {
                        $normalizedRow[$fieldSlug] = $fieldValue;
                        continue;
                    }

                    switch ($repeaterField->field_type) {
                        case 'boolean':
                            $normalizedRow[$fieldSlug] = $this->normalizeBoolean($fieldValue);
                            break;

                        case 'number':
                            $normalizedRow[$fieldSlug] = is_numeric($fieldValue) ? $fieldValue : null;
                            break;

                        case 'date':
                            $normalizedRow[$fieldSlug] = $this->normalizeDate($fieldValue);
                            break;

                        case 'datetime':
                            $normalizedRow[$fieldSlug] = $this->normalizeDatetime($fieldValue);
                            break;

                        case 'checkbox':
                            $normalizedRow[$fieldSlug] = is_array($fieldValue)
                                ? $fieldValue
                                : [(string) $fieldValue];
                            break;

                        case 'select':
                        case 'radio':
                            $option = $repeaterField->options()
                                ->where(function ($q) use ($fieldValue) {
                                    $q->where('value', (string) $fieldValue)
                                        ->orWhere('id', is_numeric($fieldValue) ? (int) $fieldValue : 0);
                                })
                                ->first();

                            $normalizedRow[$fieldSlug] = $option
                                ? $option->value
                                : (string) $fieldValue;
                            break;

                        case 'media':
                        case 'file':
                            $normalizedRow[$fieldSlug] = $this->normalizeMediaValue($fieldValue);
                            break;

                        default:
                            $normalizedRow[$fieldSlug] = is_array($fieldValue)
                                ? $fieldValue
                                : (string) $fieldValue;
                            break;
                    }
                }

                $normalizedRows[] = $normalizedRow;
            }

            $normalizedBlocks[] = [
                'custom_field_repeater_id' => $block['custom_field_repeater_id'] ?? null,
                'field_name_slug' => $block['field_name_slug'] ?? null,
                'rows' => $normalizedRows,
            ];
        }

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
                'value_json' => [
                    'repeaters' => $normalizedBlocks,
                ],
            ]
        );
    }

    /**
     * Save repeater values into the legacy custom_field_repeater_values table.
     */
    private function saveLegacyRepeaterValues(int $entityId, string $entityType, CustomField $customField, array $normalizedRows): void
    {
        // Delete old legacy repeater values for this entity+field
        DB::table('custom_field_repeater_values')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('custom_field_id', $customField->id)
            ->delete();

        if (empty($normalizedRows)) {
            return;
        }

        // Fetch repeater definitions (id keyed by slug)
        $repeaterDefs = $customField->activeRepeaters()->get()->keyBy('field_name_slug');

        foreach ($normalizedRows as $rowIndex => $row) {
            $uniqueId = $this->generateRepeaterUniqueId($entityType, $entityId, $customField->id, $rowIndex);

            foreach ($row as $fieldSlug => $fieldValue) {
                $repeaterDef = $repeaterDefs->get($fieldSlug);

                DB::table('custom_field_repeater_values')->insert([
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'custom_field_id' => $customField->id,
                    'custom_field_repeater_id' => $repeaterDef?->id,
                    'custom_field_repeater_options_id' => null,
                    'field_type' => $repeaterDef?->field_type,
                    'field_meta_value' => is_array($fieldValue) ? json_encode($fieldValue) : (string) $fieldValue,
                    'unique_id' => $uniqueId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Generate a unique ID for repeater rows.
     */
    private function generateRepeaterUniqueId(string $entityType, int $entityId, int $fieldId, int $rowIndex): string
    {
        return sprintf('%s_%d_%d_%d_%d', $entityType, $entityId, $fieldId, $rowIndex, time());
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
                // Normalize to true/false
                $raw = $value->value_json ?? $value->value_string ?? $value->value;
                $formattedValue = is_bool($raw) ? $raw : (in_array((string) $raw, ['1', 'true', 'yes'], true) ? true : false);
                break;

            case 'number':
                // Try JSON first for exact value, else decimal
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
                    // Fallback: parse from value_string (comma-separated option IDs)
                    $optionIds = array_filter(explode(',', $value->value_string ?? ''));
                    $formattedValue = array_map(fn($id) => ['custom_field_option_id' => (int) $id], $optionIds);
                }
                break;

            case 'repeater':
                $formattedValue = $value->value_json ?? [];
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
