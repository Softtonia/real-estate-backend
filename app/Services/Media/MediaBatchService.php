<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Jobs\Media\ProcessDynamicPostMediaJob;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\DynamicPost;
use App\Models\MediaBatchItem;
use App\Models\MediaFile;
use App\Models\MediaUploadBatch;
use App\Models\PostType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaBatchService
{
    /**
     * Handle batch or single file upload.
     */
    public function handleBatchUpload(Request $request, ?User $user = null): array
    {
        $user = $user ?: Auth::user() ?: User::first();
        $userId = $user ? (int) $user->id : 1;

        $batchUuid = $request->input('batch_uuid') ?: Str::uuid()->toString();
        $dynamicPostId = $request->filled('dynamic_post_id') ? (int) $request->input('dynamic_post_id') : null;
        $customFieldId = $request->filled('custom_field_id') ? (int) $request->input('custom_field_id') : null;
        $fieldSlug = $request->input('field_slug');
        $expectedCount = (int) $request->input('expected_count', 1);

        $customField = null;
        if ($customFieldId) {
            $customField = CustomField::find($customFieldId);
        } elseif ($fieldSlug) {
            $customField = CustomField::where('field_name_slug', $fieldSlug)->first();
            if ($customField) {
                $customFieldId = (int) $customField->id;
            }
        }

        $postTypeSlug = 'common';
        if ($dynamicPostId) {
            $post = DynamicPost::with('postType')->find($dynamicPostId);
            if ($post && $post->postType) {
                $postTypeSlug = Str::slug($post->postType->slug ?? $post->postType->name ?? 'common', '-');
            }
        }

        // 1. Get or create the batch record atomically
        $batch = MediaUploadBatch::firstOrCreate(
            ['batch_uuid' => $batchUuid],
            [
                'user_id' => $userId,
                'dynamic_post_id' => $dynamicPostId,
                'post_type_slug' => $postTypeSlug,
                'custom_field_id' => $customFieldId,
                'field_slug' => $fieldSlug ?: ($customField?->field_name_slug),
                'context' => 'custom-fields',
                'expected_count' => max($expectedCount, 1),
                'uploaded_count' => 0,
                'processed_count' => 0,
                'failed_count' => 0,
                'status' => 'uploading',
                'progress_percent' => 0.00,
                'expires_at' => now()->addHours(24),
            ]
        );

        // 2. Normalize incoming files array
        $rawFiles = $request->file('files');
        if (empty($rawFiles) && $request->hasFile('file')) {
            $rawFiles = [$request->file('file')];
        }

        if (empty($rawFiles)) {
            // Check if this is a featured media update request
            $targetMediaId = $request->input('featured_media_id')
                ?: $request->input('media_file_id')
                ?: $request->input('featured_id')
                ?: $request->input('id');

            $targetClientFileId = $request->input('featured_client_file_id')
                ?: $request->input('client_file_id');

            $targetUrl = $request->input('featured_url')
                ?: $request->input('url');

            $targetPath = $request->input('featured_path')
                ?: $request->input('path');

            $targetFileName = $request->input('featured_file_name')
                ?: $request->input('file_name');

            $allSavedMedia = [];

            // 1. If dynamic_post_id and customFieldId are provided, update the stored custom_field_values
            if ($dynamicPostId && $customFieldId) {
                $cfValue = CustomFieldValue::where('entity_id', $dynamicPostId)
                    ->where('entity_type', 'post')
                    ->where('custom_field_id', $customFieldId)
                    ->first();

                if ($cfValue && !empty($cfValue->value_json)) {
                    $raw = $cfValue->value_json;
                    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                    $items = [];
                    if (is_array($decoded)) {
                        $items = $decoded['media'] ?? $decoded['images'] ?? (isset($decoded[0]) ? $decoded : [$decoded]);
                    }

                    $matchedIndex = null;
                    foreach ($items as $k => $item) {
                        if (is_array($item)) {
                            $isMatch = (
                                ($targetMediaId && isset($item['id']) && (int) $item['id'] === (int) $targetMediaId)
                                || ($targetClientFileId && isset($item['client_file_id']) && (string) $item['client_file_id'] === (string) $targetClientFileId)
                                || ($targetFileName && (($item['file_name'] ?? '') === $targetFileName || ($item['original_name'] ?? '') === $targetFileName))
                                || ($targetPath && ($item['path'] ?? '') === $targetPath)
                                || ($targetUrl && ($item['url'] ?? '') === $targetUrl)
                            );

                            if ($isMatch) {
                                $matchedIndex = $k;
                                break;
                            }
                        }
                    }

                    if ($matchedIndex !== null) {
                        foreach ($items as $k => $item) {
                            if (is_array($item)) {
                                $items[$k]['is_featured'] = ($k === $matchedIndex);
                            }
                        }

                        $featuredItem = $items[$matchedIndex];
                        $featuredUrl = is_array($featuredItem) ? ($featuredItem['url'] ?? $featuredItem['path'] ?? null) : null;

                        $cfValue->update([
                            'value_json' => $items,
                            'value_string' => $featuredUrl,
                            'value_text' => $featuredUrl,
                        ]);

                        $allSavedMedia = $items;
                    }
                }
            }

            // 2. Also update MediaBatchItem records in the batch if they exist
            if ($batch->id) {
                $batchItems = MediaBatchItem::where('batch_id', $batch->id)->get();
                if ($batchItems->isNotEmpty()) {
                    foreach ($batchItems as $bItem) {
                        $isMatch = (
                            ($targetMediaId && (int) $bItem->media_file_id === (int) $targetMediaId)
                            || ($targetClientFileId && (string) $bItem->client_file_id === (string) $targetClientFileId)
                            || ($targetFileName && ($bItem->file_name === $targetFileName || $bItem->original_name === $targetFileName))
                            || ($targetPath && $bItem->path === $targetPath)
                            || ($targetUrl && $bItem->url === $targetUrl)
                        );
                        $bItem->update(['is_featured' => $isMatch]);
                    }
                }
            }

            // If we updated featured media, return success response immediately
            if (!empty($allSavedMedia) || $targetMediaId || $targetClientFileId || $targetFileName || $targetUrl) {
                return [
                    'batch_uuid' => $batch->batch_uuid,
                    'status' => 'completed',
                    'progress_percent' => 100.0,
                    'uploaded_count' => (int) $batch->uploaded_count,
                    'expected_count' => (int) $batch->expected_count,
                    'uploaded_items' => MediaBatchItem::where('batch_id', $batch->id)->get()->map(fn($item) => [
                        'client_file_id' => $item->client_file_id,
                        'media_file_id' => $item->media_file_id,
                        'url' => $item->url,
                        'path' => $item->path,
                        'file_name' => $item->file_name,
                        'original_name' => $item->original_name,
                        'is_featured' => (bool) $item->is_featured,
                        'status' => $item->status,
                    ])->values()->toArray(),
                    'saved_media' => $allSavedMedia,
                ];
            }

            throw ValidationException::withMessages([
                'files' => ['No valid file(s) provided for upload.'],
            ]);
        }

        if (!is_array($rawFiles)) {
            $rawFiles = [$rawFiles];
        }

        $clientFileIds = $request->input('client_file_ids', []);
        if (!is_array($clientFileIds)) {
            $clientFileIds = [$request->input('client_file_id', Str::uuid()->toString())];
        }

        $isFeaturedFlags = $request->input('is_featured', []);
        if (!is_array($isFeaturedFlags)) {
            $isFeaturedFlags = [$request->input('is_featured', false)];
        }

        $uploadedItems = [];
        $newMediaRecords = [];

        $fieldLabel = $customField?->field_label ?: ($fieldSlug ?: 'Media Gallery');
        $allowedExtensions = $this->resolveAllowedExtensions($customField);

        $directory = implode('/', [
            'uploads',
            'custom-fields',
            $postTypeSlug,
            Str::slug($fieldSlug ?: ($customField?->field_name_slug ?: 'gallery'), '-'),
            now()->format('Y'),
            now()->format('m'),
        ]);

        foreach ($rawFiles as $idx => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw ValidationException::withMessages([
                    'files' => ["File upload failed: " . ($file ? $file->getErrorMessage() : 'Invalid file.')],
                ]);
            }

            $clientFileId = (string) ($clientFileIds[$idx] ?? $clientFileIds[0] ?? Str::uuid()->toString());
            $isItemFeatured = filter_var($isFeaturedFlags[$idx] ?? $isFeaturedFlags[0] ?? false, FILTER_VALIDATE_BOOLEAN);

            // Check for idempotency: if already uploaded in this batch, return existing item
            $existingItem = MediaBatchItem::where('batch_id', $batch->id)
                ->where('client_file_id', $clientFileId)
                ->first();

            if ($existingItem) {
                $uploadedItems[] = $existingItem;
                continue;
            }

            $extension = strtolower($file->getClientOriginalExtension());
            if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
                throw ValidationException::withMessages([
                    'files' => ["Invalid format for {$fieldLabel}. Allowed: " . implode(', ', $allowedExtensions)],
                ]);
            }

            $maxSizeKb = $this->resolveMaxFileSizeKb($customField, $extension);
            $fileSize = $file->getSize();
            if ($maxSizeKb > 0 && ($fileSize / 1024) > $maxSizeKb) {
                $displayMb = round($maxSizeKb / 1024, 1);
                $fileTypeLabel = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? 'Image' : 'File';
                throw ValidationException::withMessages([
                    'files' => ["{$fileTypeLabel} '{$file->getClientOriginalName()}' exceeds the maximum allowed size of {$displayMb} MB."],
                ]);
            }

            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $fileName = Str::uuid()->toString() . '.' . $extension;

            $path = $file->storeAs($directory, $fileName, 'public');

            if (empty($path)) {
                throw new \RuntimeException("Could not write file to local disk: {$directory}/{$fileName}");
            }

            $url = Storage::disk('public')->url($path);

            // Create persistent MediaFile record
            $mediaRecord = MediaFile::firstOrCreate(
                ['path' => $path],
                [
                    'disk' => 'public',
                    'context' => 'custom-fields',
                    'post_type_slug' => $postTypeSlug,
                    'field_slug' => $fieldSlug ?: ($customField?->field_name_slug ?: 'gallery'),
                    'directory' => $directory,
                    'file_name' => $fileName,
                    'original_name' => $originalName,
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'size' => $fileSize,
                    'uploaded_by' => $userId,
                ]
            );

            // Create MediaBatchItem record
            $batchItem = MediaBatchItem::create([
                'batch_id' => $batch->id,
                'client_file_id' => $clientFileId,
                'media_file_id' => $mediaRecord->id,
                'file_name' => $fileName,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size' => $fileSize,
                'path' => $path,
                'url' => $url,
                'is_featured' => $isItemFeatured,
                'sort_order' => $idx,
                'status' => 'uploaded',
            ]);

            $uploadedItems[] = $batchItem;
            $newMediaRecords[] = [
                'id' => (int) $mediaRecord->id,
                'client_file_id' => $clientFileId,
                'url' => $url,
                'path' => $path,
                'file_name' => $fileName,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size' => $fileSize,
                'is_featured' => $isItemFeatured,
            ];

            // Safely dispatch background queue job if queue worker is active
            rescue(fn() => ProcessDynamicPostMediaJob::dispatch((int) $batchItem->id), null, false);
        }

        // Resolve exact featured item from explicit inputs (featured_client_file_id, featured_media_id, featured_index, or is_featured flags)
        $explicitFeaturedClientFileId = $request->input('featured_client_file_id');
        $explicitFeaturedMediaId = $request->input('featured_media_id') ?: $request->input('featured_id');
        $explicitFeaturedIndex = $request->input('featured_index');

        $featuredKey = null;
        if ($explicitFeaturedClientFileId !== null) {
            foreach ($uploadedItems as $k => $item) {
                if ((string) $item->client_file_id === (string) $explicitFeaturedClientFileId) {
                    $featuredKey = $k;
                    break;
                }
            }
        } elseif ($explicitFeaturedMediaId !== null) {
            foreach ($uploadedItems as $k => $item) {
                if ((int) $item->media_file_id === (int) $explicitFeaturedMediaId) {
                    $featuredKey = $k;
                    break;
                }
            }
        } elseif ($explicitFeaturedIndex !== null && isset($uploadedItems[(int) $explicitFeaturedIndex])) {
            $featuredKey = (int) $explicitFeaturedIndex;
        } else {
            // Check if any item was submitted with is_featured: true
            foreach ($uploadedItems as $k => $item) {
                if (!empty($item->is_featured)) {
                    $featuredKey = $k;
                    break;
                }
            }
        }

        if ($featuredKey !== null) {
            foreach ($uploadedItems as $k => $item) {
                $isFeat = ($k === $featuredKey);
                if ($item->is_featured !== $isFeat) {
                    $item->update(['is_featured' => $isFeat]);
                    $uploadedItems[$k]->is_featured = $isFeat;
                }
                if (isset($newMediaRecords[$k])) {
                    $newMediaRecords[$k]['is_featured'] = $isFeat;
                }
            }
        }

        // 3. Update batch counters atomically
        $uploadedCount = MediaBatchItem::where('batch_id', $batch->id)->count();
        $expectedCount = max((int) $batch->expected_count, $uploadedCount);
        $progress = round(($uploadedCount / $expectedCount) * 100, 2);

        $batch->update([
            'uploaded_count' => $uploadedCount,
            'expected_count' => $expectedCount,
            'progress_percent' => min(100.00, $progress),
            'status' => ($uploadedCount >= $expectedCount) ? 'completed' : 'uploading',
        ]);

        // 4. Attach new media to custom_field_values if dynamic_post_id is provided
        $allSavedMedia = [];
        if ($dynamicPostId && $customFieldId) {
            $allSavedMedia = $this->syncBatchMediaToCustomFieldValue(
                dynamicPostId: $dynamicPostId,
                customFieldId: $customFieldId,
                newMedia: $newMediaRecords,
                fieldSlug: $fieldSlug ?: ($customField?->field_name_slug)
            );
        }

        return [
            'batch_uuid' => $batch->batch_uuid,
            'status' => $batch->status,
            'progress_percent' => (float) $batch->progress_percent,
            'uploaded_count' => (int) $batch->uploaded_count,
            'expected_count' => (int) $batch->expected_count,
            'uploaded_items' => collect($uploadedItems)->map(fn($item) => [
                'client_file_id' => $item->client_file_id,
                'media_file_id' => $item->media_file_id,
                'url' => $item->url,
                'path' => $item->path,
                'file_name' => $item->file_name,
                'original_name' => $item->original_name,
                'is_featured' => (bool) $item->is_featured,
                'status' => $item->status,
            ])->values()->toArray(),
            'saved_media' => $allSavedMedia,
        ];
    }

    /**
     * Get live status and details of a batch.
     */
    public function getBatchStatus(string $batchUuid): ?array
    {
        $batch = MediaUploadBatch::with('items')->where('batch_uuid', $batchUuid)->first();

        if (!$batch) {
            return null;
        }

        return [
            'batch_uuid' => $batch->batch_uuid,
            'dynamic_post_id' => $batch->dynamic_post_id,
            'custom_field_id' => $batch->custom_field_id,
            'status' => $batch->status,
            'progress_percent' => (float) $batch->progress_percent,
            'uploaded_count' => (int) $batch->uploaded_count,
            'processed_count' => (int) $batch->processed_count,
            'failed_count' => (int) $batch->failed_count,
            'expected_count' => (int) $batch->expected_count,
            'items' => $batch->items->map(fn($item) => [
                'id' => $item->id,
                'client_file_id' => $item->client_file_id,
                'media_file_id' => $item->media_file_id,
                'url' => $item->url,
                'path' => $item->path,
                'file_name' => $item->file_name,
                'original_name' => $item->original_name,
                'is_featured' => (bool) $item->is_featured,
                'status' => $item->status,
                'error_message' => $item->error_message,
            ])->values()->toArray(),
        ];
    }

    /**
     * Link batches created during "Add Post" flow to the newly created dynamic post.
     */
    public function attachBatchesToDynamicPost(int $dynamicPostId, array $batchUuids = []): void
    {
        $batchUuids = array_values(array_filter(array_unique(array_map('trim', $batchUuids))));

        if (empty($batchUuids)) {
            return;
        }

        $batches = MediaUploadBatch::with('items')
            ->whereIn('batch_uuid', $batchUuids)
            ->get();

        foreach ($batches as $batch) {
            $batch->update(['dynamic_post_id' => $dynamicPostId]);

            if ($batch->custom_field_id) {
                $batchMedia = $batch->items->map(fn($item) => [
                    'id' => $item->media_file_id,
                    'url' => $item->url,
                    'path' => $item->path,
                    'file_name' => $item->file_name,
                    'original_name' => $item->original_name,
                    'mime_type' => $item->mime_type,
                    'extension' => $item->extension,
                    'size' => $item->size,
                    'is_featured' => (bool) $item->is_featured,
                ])->toArray();

                if (!empty($batchMedia)) {
                    $this->syncBatchMediaToCustomFieldValue(
                        dynamicPostId: $dynamicPostId,
                        customFieldId: (int) $batch->custom_field_id,
                        newMedia: $batchMedia,
                        fieldSlug: $batch->field_slug
                    );
                }
            }
        }
    }

    /**
     * Merge new batch media into custom_field_values without losing existing files.
     */
    private function syncBatchMediaToCustomFieldValue(
        int $dynamicPostId,
        int $customFieldId,
        array $newMedia,
        ?string $fieldSlug = null
    ): array {
        $cfValue = CustomFieldValue::where('entity_id', $dynamicPostId)
            ->where('entity_type', 'post')
            ->where('custom_field_id', $customFieldId)
            ->first();

        $existingMedia = [];
        if ($cfValue && !empty($cfValue->value_json)) {
            $raw = $cfValue->value_json;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($decoded)) {
                $existingMedia = isset($decoded[0]) ? $decoded : [$decoded];
            }
        }

        $mergedByPath = [];
        foreach ($existingMedia as $item) {
            if (is_array($item) && !empty($item['path'])) {
                $mergedByPath[$item['path']] = $item;
            }
        }

        foreach ($newMedia as $newItem) {
            if (!empty($newItem['path'])) {
                $mergedByPath[$newItem['path']] = $newItem;
            }
        }

        $finalItems = array_values($mergedByPath);

        // Check if any newly added item was explicitly marked as featured
        $newFeaturedKey = null;
        foreach ($finalItems as $k => $item) {
            foreach ($newMedia as $nm) {
                if (!empty($nm['is_featured']) && ($item['path'] === ($nm['path'] ?? null) || (isset($item['id'], $nm['id']) && (int) $item['id'] === (int) $nm['id']))) {
                    $newFeaturedKey = $k;
                    break 2;
                }
            }
        }

        $featuredIndex = null;
        if ($newFeaturedKey !== null) {
            $featuredIndex = $newFeaturedKey;
        } else {
            // Keep existing featured item
            foreach ($finalItems as $k => $item) {
                if (!empty($item['is_featured'])) {
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

        $firstFeatured = $finalItems[$featuredIndex] ?? ($finalItems[0] ?? null);
        $featuredUrl = is_array($firstFeatured) ? ($firstFeatured['url'] ?? $firstFeatured['path'] ?? null) : null;

        CustomFieldValue::updateOrCreate(
            [
                'entity_id' => $dynamicPostId,
                'entity_type' => 'post',
                'custom_field_id' => $customFieldId,
            ],
            [
                'value_json' => $finalItems,
                'value_string' => $featuredUrl,
                'value_text' => $featuredUrl,
            ]
        );

        return $finalItems;
    }

    /**
     * Clean up abandoned batches and orphaned files older than X hours.
     */
    public function cleanupAbandonedBatches(int $hours = 24): int
    {
        $threshold = now()->subHours($hours);

        $expiredBatches = MediaUploadBatch::with('items')
            ->where('status', '!=', 'completed')
            ->where(function ($q) use ($threshold) {
                $q->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', $threshold);
            })
            ->get();

        $cleanedCount = 0;

        foreach ($expiredBatches as $batch) {
            // Delete incomplete batch files from disk
            foreach ($batch->items as $item) {
                if ($item->status !== 'completed' && !empty($item->path)) {
                    if (Storage::disk('public')->exists($item->path)) {
                        Storage::disk('public')->delete($item->path);
                    }
                    if ($item->media_file_id) {
                        MediaFile::where('id', $item->media_file_id)->delete();
                    }
                }
            }

            $batch->update(['status' => 'abandoned']);
            $batch->items()->delete();
            $batch->delete();
            $cleanedCount++;
        }

        return $cleanedCount;
    }

    private function resolveAllowedExtensions(?CustomField $field): array
    {
        if (!$field) {
            return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'webm', 'pdf', 'docx'];
        }

        if (!empty($field->media_format)) {
            if (is_array($field->media_format)) {
                return array_map('strtolower', $field->media_format);
            }
            return array_values(array_filter(array_map('trim', explode(',', strtolower((string) $field->media_format)))));
        }

        $type = strtolower((string) ($field->field_type ?? ''));
        if (in_array($type, ['image', 'gallery'], true)) {
            return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'webm'];
        }

        if ($type === 'video') {
            return ['mp4', 'mov', 'webm', 'mkv', 'avi'];
        }

        return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'webm', 'pdf', 'docx', 'xlsx'];
    }

    private function resolveMaxFileSizeKb(?CustomField $field, string $extension = ''): int
    {
        $isImage = !empty($extension) && in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);

        if ($isImage) {
            // For images: default max 5MB (5120 KB) or custom field setting if smaller
            if (!empty($field?->media_size)) {
                $cfSizeKb = $this->parseSizeToKb((string) $field->media_size);
                return min(5120, $cfSizeKb);
            }
            return 5120; // 5 MB
        }

        // For videos and other media: use custom field dynamic setting or default 500MB
        if (!empty($field?->media_size)) {
            return $this->parseSizeToKb((string) $field->media_size);
        }

        return 512000; // 500 MB default for videos
    }

    private function parseSizeToKb(string $size): int
    {
        $size = trim($size);
        if (is_numeric($size)) {
            $num = (float) $size;
            return (int) round($num * 1024); // e.g. 50 = 50 MB = 51200 KB
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(kb|mb|gb)?$/i', $size, $matches)) {
            $num = (float) $matches[1];
            $unit = strtolower($matches[2] ?? 'mb');
            return match ($unit) {
                'kb' => (int) round($num),
                'gb' => (int) round($num * 1024 * 1024),
                default => (int) round($num * 1024),
            };
        }

        return 512000;
    }
}
