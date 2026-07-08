<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keyword extends Model
{
    protected $fillable = [
        'slug',
        'keyword_type',
        'post_type',
        'keyword_list',
    ];

    protected $casts = [
        'keyword_type' => 'integer',
        'post_type' => 'integer',
        'keyword_list' => 'array',
    ];

    public function keywordType(): BelongsTo
    {
        return $this->belongsTo(PostType::class, 'keyword_type');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(DynamicPost::class, 'post_type');
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