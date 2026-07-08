<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keyword extends Model
{
    protected $fillable = [
        'slug',
        'keyword_type',
        'post_type_id',
        'dynamic_post_id',
        'keyword_list',
        'import_uid',
        'import_file_key',
        'import_row_number',
        'last_import_batch_id',
    ];

    protected $casts = [
        'keyword_list' => 'array',
        'post_type_id' => 'integer',
        'dynamic_post_id' => 'integer',
        'import_row_number' => 'integer',
    ];

    public function postType(): BelongsTo
    {
        return $this->belongsTo(PostType::class);
    }

    public function dynamicPost(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class);
    }

    public function setKeywordListAttribute(mixed $value): void
    {
        $this->attributes['keyword_list'] = json_encode(
            self::normalizeKeywords($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public static function normalizeKeywords(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = explode(',', $value);
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatten()
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }
}