<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CustomFieldValueService
{
    /**
     * Field types that store JSON data.
     * Repeater removed because repeater values are stored separately
     * in custom_field_repeater_values table.
     */
    private const JSON_FIELD_TYPES = ['checkbox', 'media', 'file', 'image', 'gallery'];

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

            $resolved = $this->resolveValue($customField, $field, $entityId, $entityType);

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
    private function resolveValue(CustomField $customField, array $field, ?int $entityId = null, ?string $entityType = null): array
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

        $isMediaField = in_array($fieldType, ['media', 'file', 'image', 'gallery'], true);

        if (!$isMediaField && (is_null($rawValue) || $rawValue === '' || $rawValue === [])) {
            return $record;
        }

        if (is_array($rawValue) && !in_array($fieldType, ['checkbox', 'media', 'file', 'image', 'gallery'], true)) {
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
            case 'image':
            case 'gallery':
                // Fetch existing DB record to ensure we NEVER wipe out media unless explicitly requested
                $existingRecord = null;
                if ($entityId && $entityType) {
                    $existingRecord = CustomFieldValue::where('entity_type', $entityType)
                        ->where('entity_id', $entityId)
                        ->where('custom_field_id', $customField->id)
                        ->first();
                }

                $existingItems = [];
                if ($existingRecord && !empty($existingRecord->value_json)) {
                    $existingItems = $this->normalizeMediaValue($existingRecord->value_json) ?: [];
                }

                // Check for explicit removed IDs or paths (support string IDs, client_file_ids, integer IDs, paths, URLs)
                $rawRemovedIds = collect($field['removed_media_ids'] ?? $field['delete_media_ids'] ?? $field['removed_ids'] ?? [])
                    ->flatten()->filter(fn($v) => $v !== null && $v !== '')->map(fn($v) => trim((string) $v))->values()->all();

                $rawRemovedPaths = collect($field['removed_paths'] ?? $field['deleted_paths'] ?? [])
                    ->flatten()->filter(fn($v) => $v !== null && $v !== '')->map(fn($v) => trim((string) $v))->values()->all();

                $allRemovalTargets = array_values(array_unique(array_merge($rawRemovedIds, $rawRemovedPaths)));

                $isItemRemoved = function ($it) use ($allRemovalTargets): bool {
                    if (!is_array($it)) {
                        return false;
                    }
                    $idStr       = isset($it['id']) ? trim((string) $it['id']) : '';
                    $clientIdStr = isset($it['client_file_id']) ? trim((string) $it['client_file_id']) : '';
                    $pathStr     = isset($it['path']) ? trim((string) $it['path']) : '';
                    $urlStr      = isset($it['url']) ? trim((string) $it['url']) : '';
                    $origUrlStr  = isset($it['original_url']) ? trim((string) $it['original_url']) : '';
                    $fileNameStr = isset($it['file_name']) ? trim((string) $it['file_name']) : '';

                    foreach ($allRemovalTargets as $target) {
                        $t = trim((string) $target);
                        if ($t === '') continue;

                        if ($idStr !== '' && ($idStr === $t || (is_numeric($idStr) && is_numeric($t) && (int)$idStr === (int)$t))) {
                            return true;
                        }
                        if ($clientIdStr !== '' && $clientIdStr === $t) {
                            return true;
                        }
                        if ($pathStr !== '' && ($pathStr === $t || str_contains($t, $pathStr) || str_contains($pathStr, $t))) {
                            return true;
                        }
                        if ($urlStr !== '' && ($urlStr === $t || str_contains($t, $urlStr) || str_contains($urlStr, $t))) {
                            return true;
                        }
                        if ($origUrlStr !== '' && $origUrlStr === $t) {
                            return true;
                        }
                        if ($fileNameStr !== '' && $fileNameStr === $t) {
                            return true;
                        }
                    }
                    return false;
                };

                if (!empty($allRemovalTargets)) {
                    // Find matching items in existing value_json to delete
                    $toDelete = collect($existingItems)->filter(fn($it) => $isItemRemoved($it))->values()->all();

                    Log::debug('[CustomFieldValueService Delete] submitted removals', [
                        'custom_field_id' => $customField->id,
                        'targets'         => $allRemovalTargets,
                        'matched_count'   => count($toDelete),
                        'matched'         => collect($toDelete)->map(fn($it) => ['id' => $it['id'] ?? null, 'client_file_id' => $it['client_file_id'] ?? null, 'path' => $it['path'] ?? null])->values()->toArray(),
                    ]);

                    foreach ($toDelete as $item) {
                        $mediaDbId = (int) ($item['id'] ?? 0);
                        if ($mediaDbId) {
                            $mediaRecord = MediaFile::find($mediaDbId);
                            if ($mediaRecord) {
                                $disk = $mediaRecord->disk ?? 'public';
                                $filePath = $mediaRecord->path;
                                if ($filePath && Storage::disk($disk)->exists($filePath)) {
                                    Storage::disk($disk)->delete($filePath);
                                }
                                $mediaRecord->delete();
                            } else {
                                $path = $item['path'] ?? null;
                                $disk = $item['disk'] ?? 'public';
                                if ($path && Storage::disk($disk)->exists($path)) {
                                    Storage::disk($disk)->delete($path);
                                }
                            }
                        } else {
                            $path = $item['path'] ?? null;
                            if ($path) {
                                $foundRecord = MediaFile::where('path', $path)->first();
                                if ($foundRecord) {
                                    $disk = $foundRecord->disk ?? 'public';
                                    if ($foundRecord->path && Storage::disk($disk)->exists($foundRecord->path)) {
                                        Storage::disk($disk)->delete($foundRecord->path);
                                    }
                                    $foundRecord->delete();
                                }
                            }
                            $disk = $item['disk'] ?? 'public';
                            if ($path && Storage::disk($disk)->exists($path)) {
                                Storage::disk($disk)->delete($path);
                            }
                        }
                    }

                    $existingItems = collect($existingItems)
                        ->reject(fn($it) => $isItemRemoved($it))
                        ->values()
                        ->toArray();
                }

                if (array_key_exists('value_json', $field) && is_array($field['value_json'])) {
                    $finalItems = $field['value_json'];
                } else {
                    $incomingItems = $this->normalizeMediaValue($rawValue) ?: [];

                    $mergedByPath = [];
                    foreach ($existingItems as $it) {
                        if (is_array($it) && !empty($it['path'])) {
                            $mergedByPath[$it['path']] = $it;
                        }
                    }

                    foreach ($incomingItems as $it) {
                        if (is_array($it) && !empty($it['path'])) {
                            $mergedByPath[$it['path']] = array_merge($mergedByPath[$it['path']] ?? [], $it);
                        }
                    }

                    $finalItems = array_values($mergedByPath);

                    if (empty($finalItems) && !empty($incomingItems)) {
                        $finalItems = $incomingItems;
                    }
                }

                if (!empty($allRemovalTargets)) {
                    $finalItems = collect($finalItems)->reject(fn($it) => $isItemRemoved($it))->values()->toArray();
                }

                // Featured identification: stable ID identity
                $featuredRef = $field['featured_media_id'] ?? $field['featured_id'] ?? $field['featured_client_file_id'] ?? $field['featured_url'] ?? null;
                if ($featuredRef === null && is_array($rawValue)) {
                    $featuredRef = $rawValue['featured_media_id'] ?? $rawValue['featured_id'] ?? $rawValue['featured_client_file_id'] ?? $rawValue['featured_url'] ?? null;
                }

                $featuredIndex = null;
                if ($featuredRef !== null) {
                    foreach ($finalItems as $k => $item) {
                        if (($item['id'] !== null && (int) $item['id'] === (int) $featuredRef)
                            || (isset($item['client_file_id']) && (string) $item['client_file_id'] === (string) $featuredRef)
                            || (!empty($item['url']) && (string) $item['url'] === (string) $featuredRef)
                            || (!empty($item['path']) && (string) $item['path'] === (string) $featuredRef)
                            || ($featuredRef === $k)
                        ) {
                            $featuredIndex = $k;
                            break;
                        }
                    }
                }

                if ($featuredIndex === null) {
                    foreach ($finalItems as $k => $item) {
                        if (!empty($item['is_featured']) && filter_var($item['is_featured'], FILTER_VALIDATE_BOOLEAN)) {
                            $featuredIndex = $k;
                            break;
                        }
                    }
                }

                if ($featuredIndex === null && !empty($finalItems)) {
                    $featuredIndex = 0;
                }

                foreach ($finalItems as $k => $item) {
                    $finalItems[$k]['is_featured'] = ($k === $featuredIndex);
                }

                $record['value_json'] = !empty($finalItems) ? $finalItems : null;
                if (!empty($finalItems)) {
                    $firstFeatured = $finalItems[$featuredIndex] ?? ($finalItems[0] ?? null);
                    $firstUrl = is_array($firstFeatured) ? ($firstFeatured['url'] ?? $firstFeatured['path'] ?? null) : (string) $firstFeatured;
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
            $rawValue = trim($rawValue);
            if ($rawValue === '') {
                return null;
            }

            $decoded = json_decode($rawValue, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_numeric($decoded))) {
                $rawValue = $decoded;
            } elseif (str_contains($rawValue, ',')) {
                $rawValue = array_values(array_filter(array_map('trim', explode(',', $rawValue))));
            } else {
                $rawValue = [$rawValue];
            }
        }

        if (is_numeric($rawValue)) {
            $rawValue = [(int) $rawValue];
        }

        if (!is_array($rawValue)) {
            return null;
        }

        // Support wrapper structures: { images: [...], featured_media_id: 123 } or { media: [...] }
        if (isset($rawValue['images']) && is_array($rawValue['images'])) {
            $featuredRef = $rawValue['featured_media_id'] ?? $rawValue['featured_id'] ?? $rawValue['featured'] ?? $rawValue['featured_client_file_id'] ?? $rawValue['featured_url'] ?? null;
            $items = $rawValue['images'];
        } elseif (isset($rawValue['media']) && is_array($rawValue['media'])) {
            $featuredRef = $rawValue['featured_media_id'] ?? $rawValue['featured_id'] ?? $rawValue['featured'] ?? $rawValue['featured_client_file_id'] ?? $rawValue['featured_url'] ?? null;
            $items = $rawValue['media'];
        } elseif (isset($rawValue['media_files']) && is_array($rawValue['media_files'])) {
            $featuredRef = $rawValue['featured_media_id'] ?? $rawValue['featured_id'] ?? $rawValue['featured'] ?? $rawValue['featured_client_file_id'] ?? $rawValue['featured_url'] ?? null;
            $items = $rawValue['media_files'];
        } elseif (isset($rawValue['path']) || isset($rawValue['url']) || isset($rawValue['id'])) {
            $urlStr = (string) ($rawValue['url'] ?? '');
            $pathStr = (string) ($rawValue['path'] ?? '');
            if (str_contains($urlStr, ',') || str_contains($pathStr, ',')) {
                $urls = array_values(array_filter(array_map('trim', explode(',', $urlStr))));
                $paths = array_values(array_filter(array_map('trim', explode(',', $pathStr))));
                $items = [];
                $count = max(count($urls), count($paths));
                for ($i = 0; $i < $count; $i++) {
                    $u = $urls[$i] ?? ($paths[$i] ?? null);
                    $p = $paths[$i] ?? ($urls[$i] ?? null);
                    $items[] = [
                        'id' => null,
                        'url' => $u,
                        'path' => $p,
                        'is_featured' => ($i === 0 && !empty($rawValue['is_featured'])),
                    ];
                }
            } else {
                $items = [$rawValue];
            }
            $featuredRef = null;
        } else {
            $items = $rawValue;
            $featuredRef = null;
        }

        $normalized = [];

        foreach ($items as $idx => $item) {
            if (empty($item)) {
                continue;
            }

            if (is_numeric($item)) {
                $media = \App\Models\MediaFile::find((int) $item);
                if ($media) {
                    $url = $media->url ?: \Illuminate\Support\Facades\Storage::disk($media->disk ?: 'public')->url($media->path);
                    $normalized[] = [
                        'id' => (int) $media->id,
                        'url' => $url,
                        'path' => $media->path,
                        'disk' => $media->disk ?: 'public',
                        'file_name' => $media->file_name,
                        'original_name' => $media->original_name,
                        'mime_type' => $media->mime_type,
                        'extension' => $media->extension,
                        'size' => $media->size,
                        'is_featured' => false,
                    ];
                } else {
                    $normalized[] = [
                        'id' => (int) $item,
                        'url' => null,
                        'path' => null,
                        'is_featured' => false,
                    ];
                }
                continue;
            }

            if (is_string($item)) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }

                if (str_contains($item, ',')) {
                    $subParts = array_values(array_filter(array_map('trim', explode(',', $item))));
                    foreach ($subParts as $subPart) {
                        $path = $this->extractPathFromUrlOrPath($subPart);
                        $media = $path ? \App\Models\MediaFile::where('path', $path)->orWhere('file_name', basename($path))->first() : null;
                        $normalized[] = [
                            'id' => $media ? (int) $media->id : null,
                            'url' => str_starts_with($subPart, 'http') ? $subPart : ($media ? ($media->url ?: \Illuminate\Support\Facades\Storage::disk($media->disk ?: 'public')->url($path)) : \Illuminate\Support\Facades\Storage::disk('public')->url($path)),
                            'path' => $path ?: $subPart,
                            'disk' => 'public',
                            'file_name' => basename($path ?: $subPart),
                            'original_name' => $media?->original_name ?: basename($path ?: $subPart),
                            'mime_type' => $media?->mime_type,
                            'extension' => pathinfo($path ?: $subPart, PATHINFO_EXTENSION),
                            'size' => $media?->size,
                            'is_featured' => false,
                        ];
                    }
                    continue;
                }

                $path = $this->extractPathFromUrlOrPath($item);
                $media = $path ? \App\Models\MediaFile::where('path', $path)->orWhere('file_name', basename($path))->first() : null;
                $normalized[] = [
                    'id' => $media ? (int) $media->id : null,
                    'url' => str_starts_with($item, 'http') ? $item : ($media ? ($media->url ?: \Illuminate\Support\Facades\Storage::disk($media->disk ?: 'public')->url($path)) : \Illuminate\Support\Facades\Storage::disk('public')->url($path)),
                    'path' => $path ?: $item,
                    'disk' => 'public',
                    'file_name' => basename($path ?: $item),
                    'original_name' => $media?->original_name ?: basename($path ?: $item),
                    'mime_type' => $media?->mime_type,
                    'extension' => pathinfo($path ?: $item, PATHINFO_EXTENSION),
                    'size' => $media?->size,
                    'is_featured' => false,
                ];
                continue;
            }

            if (is_array($item)) {
                $path = $item['path'] ?? null;
                $url = $item['url'] ?? null;

                if (($url && str_contains((string) $url, ',')) || ($path && str_contains((string) $path, ','))) {
                    $urls = array_values(array_filter(array_map('trim', explode(',', (string) $url))));
                    $paths = array_values(array_filter(array_map('trim', explode(',', (string) $path))));
                    $count = max(count($urls), count($paths));
                    for ($k = 0; $k < $count; $k++) {
                        $u = $urls[$k] ?? ($paths[$k] ?? null);
                        $p = $paths[$k] ?? ($this->extractPathFromUrlOrPath($u) ?: $u);
                        $media = $p ? \App\Models\MediaFile::where('path', $p)->orWhere('file_name', basename($p))->first() : null;
                        $normalized[] = [
                            'id' => $media ? (int) $media->id : null,
                            'url' => $u ?: ($media ? ($media->url ?: \Illuminate\Support\Facades\Storage::disk('public')->url($p)) : null),
                            'path' => $p,
                            'disk' => 'public',
                            'file_name' => basename($p ?: $u),
                            'original_name' => $media?->original_name ?: basename($p ?: $u),
                            'mime_type' => $media?->mime_type,
                            'extension' => pathinfo($p ?: $u, PATHINFO_EXTENSION),
                            'size' => $media?->size,
                            'is_featured' => false,
                        ];
                    }
                    continue;
                }

                if (!$path && $url) {
                    $path = $this->extractPathFromUrlOrPath((string) $url);
                }

                if (!$url && $path) {
                    $url = \Illuminate\Support\Facades\Storage::disk($item['disk'] ?? 'public')->url($path);
                }

                $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
                if (!$id && $path) {
                    $media = \App\Models\MediaFile::where('path', $path)->orWhere('file_name', basename($path))->first();
                    if ($media) {
                        $id = (int) $media->id;
                    }
                }

                $isFeatured = false;
                if ($featuredRef !== null) {
                    $isFeatured = ($id !== null && (int) $id === (int) $featuredRef)
                        || (isset($item['client_file_id']) && (string) $item['client_file_id'] === (string) $featuredRef)
                        || ($url !== null && (string) $url === (string) $featuredRef)
                        || ($path !== null && (string) $path === (string) $featuredRef)
                        || ($featuredRef === $idx);
                } elseif (isset($item['is_featured'])) {
                    $isFeatured = filter_var($item['is_featured'], FILTER_VALIDATE_BOOLEAN) || in_array($item['is_featured'], [1, '1', 'true', true], true);
                }

                $mimeType = $item['mime_type'] ?? null;
                $ext = strtolower($item['extension'] ?? pathinfo($path ?: (string) $url, PATHINFO_EXTENSION));
                $isVideo = !empty($item['is_video'])
                    || ($mimeType && str_starts_with($mimeType, 'video/'))
                    || in_array($ext, ['mp4', 'mov', 'webm', 'ogg', 'mkv', 'avi'], true);

                $normalized[] = array_merge($item, [
                    'id' => $id,
                    'url' => $url,
                    'path' => $path,
                    'disk' => $item['disk'] ?? 'public',
                    'file_name' => $item['file_name'] ?? ($path ? basename($path) : null),
                    'original_name' => $item['original_name'] ?? ($path ? basename($path) : null),
                    'mime_type' => $mimeType,
                    'extension' => $ext,
                    'is_video' => $isVideo,
                    'is_featured' => $isFeatured,
                ]);
            }
        }

        $unique = [];
        $seen = [];
        foreach ($normalized as $normItem) {
            $key = $normItem['path'] ?? $normItem['url'] ?? null;
            if (!$key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $normItem;
        }

        $featuredIndex = null;
        if ($featuredRef !== null) {
            foreach ($unique as $k => $item) {
                if (($item['id'] !== null && (int) $item['id'] === (int) $featuredRef)
                    || (isset($item['client_file_id']) && (string) $item['client_file_id'] === (string) $featuredRef)
                    || (!empty($item['url']) && (string) $item['url'] === (string) $featuredRef)
                    || (!empty($item['path']) && (string) $item['path'] === (string) $featuredRef)
                    || ($featuredRef === $k)
                ) {
                    $featuredIndex = $k;
                    break;
                }
            }
        }

        if ($featuredIndex === null) {
            foreach ($unique as $k => $item) {
                if (!empty($item['is_featured']) && filter_var($item['is_featured'], FILTER_VALIDATE_BOOLEAN)) {
                    $featuredIndex = $k;
                    break;
                }
            }
        }

        if ($featuredIndex === null && !empty($unique)) {
            $featuredIndex = 0;
        }

        foreach ($unique as $k => $item) {
            $unique[$k]['is_featured'] = ($k === $featuredIndex);
        }

        return $unique;
    }

    private function extractPathFromUrlOrPath(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);

        if (str_contains($value, '/storage/')) {
            return substr($value, strpos($value, '/storage/') + 9);
        }

        if (str_starts_with($value, 'storage/')) {
            return substr($value, 8);
        }

        if (str_starts_with($value, 'uploads/')) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($value, PHP_URL_PATH);
            if ($parsed && str_contains($parsed, '/storage/')) {
                return substr($parsed, strpos($parsed, '/storage/') + 9);
            }
        }

        return $value;
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