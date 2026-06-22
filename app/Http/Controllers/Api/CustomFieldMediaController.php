<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\PostType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomFieldMediaController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'custom_field_id' => ['required', 'integer', 'exists:custom_fields,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'files' => ['required', 'array', 'min:1'],
                'files.*' => ['required', 'file'],
            ]);

            $field = CustomField::find($validated['custom_field_id']);

            if (!$field || !in_array($field->field_type, ['media', 'file'], true)) {
                throw ValidationException::withMessages([
                    'custom_field_id' => ['This custom field is not a media or file field.'],
                ]);
            }

            $files = $request->file('files');
            $mediaLimit = (int) ($field->media_limit ?? 1);

            if (count($files) > $mediaLimit) {
                throw ValidationException::withMessages([
                    'files' => ['You can upload maximum ' . $mediaLimit . ' file(s).'],
                ]);
            }

            $allowedExtensions = $this->allowedExtensions($field);
            $maxSizeKb = $this->parseMediaSizeToKb($field->media_size);

            $postTypeSlug = 'common';

            if (!empty($validated['post_type_id'])) {
                $postType = PostType::find($validated['post_type_id']);
                $postTypeSlug = $postType ? Str::slug($postType->slug ?? $postType->name, '-') : 'common';
            }

            $fieldSlug = Str::slug($field->field_name_slug ?? $field->field_label, '-');

            $uploadedFiles = [];

            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());

                if (!in_array($extension, $allowedExtensions, true)) {
                    throw ValidationException::withMessages([
                        'files' => ['Invalid file format. Allowed formats: ' . implode(', ', $allowedExtensions)],
                    ]);
                }

                if (($file->getSize() / 1024) > $maxSizeKb) {
                    throw ValidationException::withMessages([
                        'files' => ['File size must not be greater than ' . $field->media_size],
                    ]);
                }

                $fileName = Str::uuid()->toString() . '.' . $extension;

                $directory = implode('/', [
                    'uploads',
                    'custom-fields',
                    $postTypeSlug,
                    $fieldSlug,
                    now()->format('Y'),
                    now()->format('m'),
                ]);

                $path = $file->storeAs($directory, $fileName, 'public');

                $uploadedFiles[] = [
                    'disk' => 'public',
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                    'file_name' => $fileName,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'extension' => $extension,
                    'size' => $file->getSize(),
                    'size_kb' => round($file->getSize() / 1024, 2),
                    'custom_field_id' => $field->id,
                    'field_name_slug' => $field->field_name_slug,
                ];
            }

            return response()->json([
                'status' => true,
                'message' => 'Custom field file uploaded successfully.',
                'data' => $uploadedFiles,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to upload custom field file.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function allowedExtensions(CustomField $field): array
    {
        if (!empty($field->media_format)) {
            return collect(explode(',', $field->media_format))
                ->map(fn($format) => strtolower(trim($format)))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        if ($field->field_type === 'media') {
            return ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        }

        return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
    }

    private function parseMediaSizeToKb(?string $size): int
    {
        if (empty($size)) {
            return 10240;
        }

        $size = strtolower(trim($size));

        if (preg_match('/^(\d+)\s*(kb|mb|gb)?$/', $size, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2] ?? 'kb';

            return match ($unit) {
                'gb' => $value * 1024 * 1024,
                'mb' => $value * 1024,
                default => $value,
            };
        }

        return 10240;
    }
}